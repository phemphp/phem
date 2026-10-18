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

namespace Phem\Tests\Runtime;

use Phem\BrowserEventDispatcher;
use Phem\Event;
use PHPUnit\Framework\TestCase;

final class BrowserEventDispatcherTest extends TestCase
{
    public function testEachEventHasItsOwnDispatcher(): void
    {
        self::assertNotSame((new Event('click'))->dispatcher, Event::fromArray(['type' => 'click'])->dispatcher);
    }

    public function testAnEventCanUseTheDispatcherService(): void
    {
        $dispatcher = new BrowserEventDispatcher();

        self::assertSame($dispatcher, Event::fromArray(['type' => 'click'], null, $dispatcher)->dispatcher);
    }

    public function testResetRemovesTheBrowserEvents(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $dispatcher->dispatch('toast');
        $dispatcher->reset();

        self::assertSame([], $dispatcher->dispatched());
    }

    public function testDispatchKeepsTheBrowserEventsInTheirOrder(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $dispatcher->dispatch('toast', ['message' => 'Saved']);
        $dispatcher->dispatch('phem.saved:item-1');

        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'Saved']], ['name' => 'phem.saved:item-1', 'detail' => null]], $dispatcher->dispatched());
    }

    public function testDispatchRejectsANameThatIsNotValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The name of a browser event has only letters, digits and "_-.:", "a toast" given.');

        (new BrowserEventDispatcher())->dispatch('a toast');
    }

    public function testDispatchRejectsADetailThatJsonCannotEncode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The detail of the browser event "toast" cannot be JSON');

        (new BrowserEventDispatcher())->dispatch('toast', ["\xB1"]);
    }
}
