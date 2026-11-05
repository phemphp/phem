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

namespace Phem\Test;

use Phem\Stream\MemoryPublisher;

/**
 * The Mercure hub of a test with a real browser (Playwright) whose requests the kernel of the test
 * process handles (as playwright-symfony does). With `framework.test: true`, Phem publishes to
 * memory (see MemoryPublisher), and the browser has no hub. A browser with its own server process
 * (Panther) does not work: its updates are in the memory of that process.
 *
 * Add initScript() to each page before its scripts: it replaces `EventSource` with a fake. After
 * each step that can publish, evaluate deliverScript() in each page: the same script gives each
 * page its new updates, as the hub does. Then wait until the root element has no
 * `data-phem-push-pending` attribute: the page rendered the pushes and their refreshes.
 *
 *     $hub = $this->browserHub();
 *     $page->addInitScript(BrowserHub::initScript());
 *     // ... a step of the other user, which publishes
 *     $page->evaluate($hub->deliverScript());
 *     $page->waitForSelector('[data-controller~="phem"]:not([data-phem-push-pending])');
 *
 * A step that publishes out of a request (a command, a data fixture) calls Streams::flush() itself.
 */
final class BrowserHub
{
    private const SCRIPT = __DIR__.'/Resources/phem_test_hub.js';

    /** The index of the first update of the hub: the updates before it are not for the pages. */
    private readonly int $first;

    public function __construct(
        private readonly MemoryPublisher $publisher,
    ) {
        $this->first = \count($publisher->updates());
    }

    /** The script that replaces `EventSource` with the fake hub. */
    public static function initScript(): string
    {
        if (!is_file(self::SCRIPT) || false === $script = file_get_contents(self::SCRIPT)) {
            throw new \RuntimeException(\sprintf('The script of the test hub is missing: "%s".', self::SCRIPT));
        }

        return $script;
    }

    /**
     * The script that gives a page the updates of the hub (`phemTestHub.deliver()`). Evaluate it
     * in each page after each step: each connection of a page gets each update one time, and
     * only the updates published after it opened. With no update, the script does nothing.
     *
     * @phpstan-impure
     */
    public function deliverScript(): string
    {
        $updates = [];
        foreach (\array_slice($this->publisher->updates(), $this->first, preserve_keys: true) as $index => $update) {
            $updates[] = ['index' => $index, 'time' => $update['time'], 'topics' => $update['topics'], 'data' => $update['data']];
        }

        // JSON is a JavaScript expression. The HEX escapes keep a `</script>` of the markup safe.
        return 'window.phemTestHub.deliver('.json_encode($updates, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION).')';
    }
}
