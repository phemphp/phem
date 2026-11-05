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

use Phem\Async\AsyncHandlerMessage;
use Phem\Async\MemoryBus;
use PHPUnit\Framework\TestCase;

final class MemoryBusTest extends TestCase
{
    public function testBeforeKeepTheMessagesGoToTheBusOfTheApplication(): void
    {
        $app = new SpyBus();
        $bus = new MemoryBus($app);

        $bus->dispatch($message = self::message());

        self::assertSame([$message], $app->messages);
        self::assertSame([], $bus->take());
    }

    public function testAfterKeepTheMessagesWaitForSettle(): void
    {
        $app = new SpyBus();
        $bus = new MemoryBus($app);
        $bus->keep();

        $bus->dispatch($message = self::message());

        self::assertSame([], $app->messages);
        self::assertSame([$message], $bus->take());
        self::assertSame([], $bus->take(), 'a message waits one time');
    }

    private static function message(): AsyncHandlerMessage
    {
        return new AsyncHandlerMessage('App\\Ui\\HomePage', 'https://localhost/', [], '{}', [], 'phem0123456789ab.export', [], null);
    }
}
