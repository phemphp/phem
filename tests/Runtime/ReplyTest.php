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
use Phem\Reply;
use Phem\Tests\Fixtures\Replies;
use Phem\ToastType;
use PHPUnit\Framework\TestCase;

final class ReplyTest extends TestCase
{
    public function testToastDispatchesAToastWithTheOptions(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        new Reply($dispatcher)->toast('The file is in your downloads.', ToastType::Success, title: 'Export complete', id: 'export');

        self::assertSame([['name' => 'toast', 'detail' => ['message' => 'The file is in your downloads.', 'type' => 'success', 'title' => 'Export complete', 'id' => 'export']]], $dispatcher->dispatched());
    }

    public function testAnnounceDispatchesTheMessageForScreenReaders(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        new Reply($dispatcher)->announce('3 results');

        self::assertSame([['name' => 'announce', 'detail' => ['message' => '3 results']]], $dispatcher->dispatched());
    }

    public function testAnnounceRejectsAnEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The message of an announcement cannot be empty.');

        Replies::make()->announce(' ');
    }

    public function testDispatchSendsABrowserEvent(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        new Reply($dispatcher)->dispatch('saved', ['id' => 1]);

        self::assertSame([['name' => 'saved', 'detail' => ['id' => 1]]], $dispatcher->dispatched());
    }

    public function testDispatchRejectsAReservedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"phem:call"');

        Replies::make()->dispatch('call');
    }

    public function testRefreshTellsThatTheHandlerRendersTheFullPage(): void
    {
        $reply = Replies::make();
        self::assertFalse($reply->refreshes());

        $reply->refresh();
        self::assertTrue($reply->refreshes());
    }

    public function testClearFilesTellsThatTheHandlerTookTheFiles(): void
    {
        $reply = Replies::make();
        self::assertFalse($reply->clearsFiles());

        $reply->clearFiles();
        self::assertTrue($reply->clearsFiles());
    }

    public function testAReplyUsesTheDispatcherThatItGets(): void
    {
        $dispatcher = new BrowserEventDispatcher();
        $dispatcher->dispatch('first');

        // Two replies of one event send to the same dispatcher, in order.
        new Reply($dispatcher)->toast('Saved');
        new Reply($dispatcher)->dispatch('saved');

        self::assertSame(['first', 'toast', 'saved'], array_column($dispatcher->dispatched(), 'name'));
    }
}
