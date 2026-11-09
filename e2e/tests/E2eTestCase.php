<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests;

use Playwright\Page\PageInterface;
use Playwright\Symfony\Client\PlaywrightKernelClient;
use Playwright\Symfony\Test\PlaywrightTestCase;

/**
 * A test in a real browser. visit() opens a page in the first browser, and visitInNewBrowser()
 * opens a page in another browser (its own cookies and storage), as another user.
 *
 * When a test fails, each page goes to var/failures/: a screenshot and the HTML of the DOM.
 */
abstract class E2eTestCase extends PlaywrightTestCase
{
    /**
     * Keeps the open EventSource objects of the page in window.openStreams. It wraps the
     * EventSource class, so it does not depend on the phem controller.
     */
    private const STREAMS_SCRIPT = <<<'JS'
        (() => {
            const Native = window.EventSource
            if (!Native) return
            const open = window.openStreams = new Set()
            window.EventSource = class extends Native {
                constructor(...args) {
                    super(...args)
                    this.addEventListener('open', () => open.add(this))
                    this.addEventListener('error', () => this.readyState === Native.OPEN || open.delete(this))
                }

                close() {
                    open.delete(this)
                    super.close()
                }
            }
        })()
        JS;

    /** @var list<PageInterface> */
    private array $pages = [];
    /** @var \WeakMap<PlaywrightKernelClient, true> the clients with the script of the streams */
    private \WeakMap $watched;

    protected function setUp(): void
    {
        parent::setUp();

        $this->watched = new \WeakMap();
    }

    protected function visit(string $path): PageInterface
    {
        return $this->open(static::getPlaywrightClient(), $path);
    }

    protected function visitInNewBrowser(string $path): PageInterface
    {
        return $this->open(static::createPlaywrightClient(), $path);
    }

    /** Waits until the EventSource of the page is open: the hub accepted the cookie of the page. */
    protected function waitForStream(PageInterface $page): void
    {
        $page->waitForFunction('() => window.openStreams?.size > 0');
    }

    protected function tearDown(): void
    {
        // Before the parent closes the pages.
        if ($this->status()->isFailure() || $this->status()->isError()) {
            $this->savePages();
        }

        $this->pages = [];

        parent::tearDown();
    }

    private function open(PlaywrightKernelClient $client, string $path): PageInterface
    {
        if (!isset($this->watched[$client])) {
            $client->context()?->addInitScript(self::STREAMS_SCRIPT);
            $this->watched[$client] = true;
        }

        $page = $client->visit($path);

        // The first browser visits again with the same page.
        if (!\in_array($page, $this->pages, true)) {
            $this->pages[] = $page;
        }

        return $page;
    }

    private function savePages(): void
    {
        $dir = \dirname(__DIR__).'/var/failures';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        foreach ($this->pages as $i => $page) {
            $name = \sprintf('%s/%s-%s-%d', $dir, (new \ReflectionClass($this))->getShortName(), $this->name(), $i);

            // Each file on its own: the HTML can help when the screenshot fails.
            try {
                file_put_contents($name.'.html', $page->content());
            } catch (\Throwable) {
                // The page is closed: nothing to save.
            }

            try {
                $page->screenshot($name.'.png', ['fullPage' => true]);
            } catch (\Throwable) {
                // The page is closed, or it is too large for a screenshot.
            }
        }
    }
}
