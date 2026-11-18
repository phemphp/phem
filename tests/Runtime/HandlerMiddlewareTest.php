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

use Phem\Argument\CallableArguments;
use Phem\Event;
use Phem\Handler\HandlerCall;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\HandlerMiddleware;
use Phem\Reply;
use PHPUnit\Framework\TestCase;
use Phem\Tests\Fixtures\Replies;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

final class HandlerMiddlewareTest extends TestCase
{
    public function testMiddlewaresRunAroundTheHandlerInOrder(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $invoker = new HandlerInvoker([new LoggingMiddleware($log, 'a'), new LoggingMiddleware($log, 'b')]);

        $result = $invoker->invoke(static function (Event $e) use ($log): string {
            $log[] = 'handler '.$e->type;

            return 'done';
        }, new Event('click'), Replies::make());

        self::assertSame('done', $result);
        self::assertSame(['a before', 'b before', 'handler click', 'b after', 'a after'], $log->getArrayCopy());
    }

    public function testHandlerGetsTheEventAtAnyPositionAndTheRequest(): void
    {
        $request = Request::create('/page', 'POST');

        $result = new HandlerInvoker()->invoke(static fn (Request $r, Event $e): string => $r->getPathInfo().' '.$e->type, new Event('click'), Replies::make(), request: $request);

        self::assertSame('/page click', $result);
    }

    public function testAHandlerGetsTheReplyAtAnyPosition(): void
    {
        $event = new Event('click');
        $reply = Replies::make();
        $invoker = new HandlerInvoker();

        self::assertSame([$reply, $event], $invoker->invoke(static fn (Reply $r, Event $e): array => [$r, $e], $event, $reply));
        self::assertSame([$event, $reply], $invoker->invoke(static fn (Event $e, Reply $r): array => [$e, $r], $event, $reply));
        self::assertSame($reply, $invoker->invoke(static fn (Reply $r): Reply => $r, $event, $reply));
    }

    public function testAHandlerCallGivesTheReplyAfterItsArguments(): void
    {
        $reply = Replies::make();
        $call = HandlerCall::bind(static fn (int $index, Reply $r): array => [$index, $r], [1]);

        self::assertNotNull($call);
        self::assertSame([1, $reply], new HandlerInvoker()->invoke($call, new Event('click'), $reply));
    }

    public function testHandlerWithNoParametersResolvesNoArguments(): void
    {
        $resolver = new class implements ArgumentResolverInterface {
            public int $calls = 0;

            /**
             * @return list<mixed>
             */
            public function getArguments(Request $request, callable $controller, ?\ReflectionFunctionAbstract $reflector = null): array
            {
                ++$this->calls;

                return [];
            }
        };

        $result = new HandlerInvoker([], new CallableArguments($resolver))->invoke(static fn (): string => 'done', new Event('click'), Replies::make());

        self::assertSame('done', $result);
        self::assertSame(0, $resolver->calls);
    }

    public function testMiddlewareCanStopTheEvent(): void
    {
        $called = false;
        $stop = new class implements HandlerMiddleware {
            public function handle(\Closure $handler, Event $event, Reply $reply, \Closure $next): mixed
            {
                return 'stopped';
            }
        };

        $result = new HandlerInvoker([$stop])->invoke(static function () use (&$called): void { $called = true; }, new Event('click'), Replies::make());

        self::assertSame('stopped', $result);
        self::assertFalse($called);
    }

    public function testMiddlewaresFromAnIterator(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $invoker = new HandlerInvoker(new \ArrayIterator([new LoggingMiddleware($log, 'a')]));

        $invoker->invoke(static function () use ($log): void { $log[] = 'handler'; }, new Event('click'), Replies::make());

        self::assertSame(['a before', 'handler', 'a after'], $log->getArrayCopy());
    }
}

final class LoggingMiddleware implements HandlerMiddleware
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly \ArrayObject $log, private readonly string $name)
    {
    }

    public function handle(\Closure $handler, Event $event, Reply $reply, \Closure $next): mixed
    {
        $this->log[] = $this->name.' before';

        try {
            return $next();
        } finally {
            $this->log[] = $this->name.' after';
        }
    }
}
