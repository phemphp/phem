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

namespace Phem\Tests\Test;

use Phem\Stream\MemoryPublisher;
use Phem\Test\BrowserHub;
use Phem\Test\InteractsWithPhem;
use Phem\Test\Internal\StreamTopics;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BrowserHubTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheInitScriptReplacesEventSourceWithTheFakeHub(): void
    {
        $script = BrowserHub::initScript();

        self::assertStringContainsString('window.EventSource = PhemTestEventSource', $script);
        self::assertStringContainsString('window.phemTestHub', $script);
    }

    public function testTheDeliverScriptGivesThePagesTheUpdatesOfTheirTopics(): void
    {
        // An update before the hub is not for the pages.
        $this->visit('/guestbook/lobby')->fill('input[name=text]', 'Before')->click('#sign');
        $hub = $this->browserHub();
        self::assertSame('window.phemTestHub.deliver([])', $hub->deliverScript());

        $page = $this->visit('/guestbook/lobby');
        $this->visit('/guestbook/lobby')->fill('input[name=text]', 'Hi </script>')->click('#sign');
        $script = $hub->deliverScript();

        self::assertStringStartsWith('window.phemTestHub.deliver([{"index":1,', $script);
        self::assertStringNotContainsString('</script>', $script, 'The markup cannot end the script of a page.');
        self::assertStringNotContainsString('"private"', $script);

        // The topics of the updates are the topics of the page.
        $updates = json_decode(substr($script, \strlen('window.phemTestHub.deliver('), -1), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($updates);
        $topics = StreamTopics::of((string) $page->document()->querySelector('[data-phem-stream-value]')?->getAttribute('data-phem-stream-value'));
        self::assertNotSame([], array_intersect(array_merge(...array_column($updates, 'topics')), $topics));
        self::assertStringContainsString('Hi &lt;/script&gt;', implode('', array_column($updates, 'data')));

        // The same script for each page: each page keeps the index of the updates that it got.
        self::assertSame($script, $hub->deliverScript());
    }

    public function testTheDeliverScriptHasTheIndexTheTimeTheTopicsAndTheData(): void
    {
        $publisher = new MemoryPublisher();
        $publisher->publish(['phem:old'], 'Old', false);
        $hub = new BrowserHub($publisher);
        $publisher->publish(['phem:a', 'phem:b'], '<turbo-stream action="remove" target="x"></turbo-stream>', true);
        $script = $hub->deliverScript();

        // The HEX escapes: no `<` of the markup in the script.
        self::assertStringNotContainsString('<', $script);
        self::assertStringStartsWith('window.phemTestHub.deliver(', $script);
        self::assertSame([['index' => 1, 'time' => $publisher->updates()[1]['time'], 'topics' => ['phem:a', 'phem:b'], 'data' => '<turbo-stream action="remove" target="x"></turbo-stream>']], json_decode(substr($script, \strlen('window.phemTestHub.deliver('), -1), true, 512, \JSON_THROW_ON_ERROR));
    }
}
