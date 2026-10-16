<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Event;
use Rephp\Handler\HandlerInvoker;
use Rephp\Handler\HandlerMiddleware;

final class HandlerMiddlewareTest extends TestCase
{
    public function testMiddlewaresRunAroundTheHandlerInOrder(): void
    {
        $log = new \ArrayObject();
        $invoker = new HandlerInvoker([new LoggingMiddleware($log, 'a'), new LoggingMiddleware($log, 'b')]);

        $result = $invoker->invoke(static function (Event $e) use ($log): string {
            $log[] = 'handler '.$e->type;

            return 'done';
        }, new Event('click'));

        self::assertSame('done', $result);
        self::assertSame(['a before', 'b before', 'handler click', 'b after', 'a after'], $log->getArrayCopy());
    }

    public function testMiddlewareCanStopTheEvent(): void
    {
        $called = false;
        $stop = new class implements HandlerMiddleware {
            public function handle(\Closure $handler, Event $event, \Closure $next): mixed
            {
                return 'stopped';
            }
        };

        $result = new HandlerInvoker([$stop])->invoke(static function () use (&$called): void { $called = true; }, new Event('click'));

        self::assertSame('stopped', $result);
        self::assertFalse($called);
    }

    public function testMiddlewaresFromAnIterator(): void
    {
        $log = new \ArrayObject();
        $invoker = new HandlerInvoker(new \ArrayIterator([new LoggingMiddleware($log, 'a')]));

        $invoker->invoke(static function () use ($log): void { $log[] = 'handler'; }, new Event('click'));

        self::assertSame(['a before', 'handler', 'a after'], $log->getArrayCopy());
    }
}

final class LoggingMiddleware implements HandlerMiddleware
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly \ArrayObject $log, private readonly string $name)
    {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $this->log[] = $this->name.' before';
        try {
            return $next();
        } finally {
            $this->log[] = $this->name.' after';
        }
    }
}
