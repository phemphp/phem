<?php

declare(strict_types=1);

namespace App\Tests;

use Playwright\Exception\TimeoutException;
use Playwright\Page\PageInterface;
use Playwright\Symfony\Test\PlaywrightTestCase;

/**
 * A test of a demo page in a real browser. Playwright PHP sends the requests of the browser to
 * the kernel of the demo, so the test needs no web server.
 *
 * When a test fails, the page goes to var/failures/: a screenshot and the HTML of the DOM. An
 * incomplete or skipped test after a visit saves its page too.
 */
abstract class DemoTestCase extends PlaywrightTestCase
{
    /** The controllers that are not connected: `name` on the element `tag`. */
    private const string NOT_CONNECTED = <<<'JS'
        () => window.Stimulus ? [...document.querySelectorAll('[data-controller]')].flatMap((el) =>
            el.dataset.controller.split(/\s+/).filter(Boolean)
                .filter((name) => !window.Stimulus.getControllerForElementAndIdentifier(el, name))
                .map((name) => `${name} on <${el.localName}>`)) : ['Stimulus (window.Stimulus is not set)']
        JS;

    private ?PageInterface $page = null;

    private bool $passed = false;

    /** Opens a page of the demo, for example `visit('/')`. */
    protected function visit(string $path): PageInterface
    {
        $this->page = static::getPlaywrightClient()->visit($path);
        self::assertResponseIsSuccessful();

        return $this->page;
    }

    /** Opens the demo page of a component, for example `visitComponent('dialog')`. */
    protected function visitComponent(string $slug): PageInterface
    {
        $page = $this->visit('/components/'.$slug);

        // The controllers are lazy: wait until each controller of the page is connected. A
        // controller that is not enabled in controllers.json never connects: the test names it.
        try {
            $page->waitForFunction('() => ('.self::NOT_CONNECTED.')().length === 0', null, ['timeout' => 10_000]);
        } catch (TimeoutException) {
            self::fail('These controllers did not connect: '.implode(', ', $page->evaluate(self::NOT_CONNECTED)).'.');
        }

        return $page;
    }

    /** PHPUnit calls this method only when the test passes, before tearDown(). */
    protected function assertPostConditions(): void
    {
        $this->passed = true;
    }

    protected function tearDown(): void
    {
        // Before the parent closes the page.
        if (null !== $this->page && !$this->passed) {
            $this->savePage($this->page);
        }

        $this->page = null;
        $this->passed = false;

        parent::tearDown();
    }

    private function savePage(PageInterface $page): void
    {
        $dir = \dirname(__DIR__).'/var/failures';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $name = \sprintf('%s/%s-%s', $dir, (new \ReflectionClass($this))->getShortName(), $this->name());

        try {
            $focused = $page->evaluate('() => document.activeElement?.outerHTML.slice(0, 300) ?? ""');
            file_put_contents($name.'.html', '<!-- focused: '.$focused.' -->'.\PHP_EOL.$page->content());
            $page->screenshot($name.'.png', ['fullPage' => true]);
        } catch (\Throwable) {
            // The page is closed: nothing to save.
        }
    }
}
