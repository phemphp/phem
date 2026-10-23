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

use Phem\Attribute\Transactional;
use Phem\Event;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\TransactionalMiddleware;
use Phem\Handler\TransactionRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class TransactionalTest extends TestCase
{
    /** @var \ArrayObject<int, string> the transaction events and the handler calls, in order */
    private \ArrayObject $log;

    protected function setUp(): void
    {
        $this->log = new \ArrayObject();
    }

    public function testHandlerOfATransactionalComponentRunsInATransaction(): void
    {
        $this->dispatch(new TransactionalComponent($this->log)->add(...));
        self::assertSame(['begin', 'add', 'commit'], $this->log->getArrayCopy());
    }

    public function testClosureOfATransactionalComponentRunsInATransaction(): void
    {
        $this->dispatch(new TransactionalComponent($this->log)->closure());
        self::assertSame(['begin', 'closure', 'commit'], $this->log->getArrayCopy());
    }

    public function testTransactionalMethodRunsInATransaction(): void
    {
        $this->dispatch(new MethodTransactionalComponent($this->log)->save(...));
        self::assertSame(['begin', 'save', 'commit'], $this->log->getArrayCopy());
    }

    public function testOtherHandlersOfAComponentWithATransactionalMethodRunWithoutATransaction(): void
    {
        $component = new MethodTransactionalComponent($this->log);
        $this->dispatch($component->toggle(...));
        $this->dispatch($component->closure());

        self::assertSame(['toggle', 'closure'], $this->log->getArrayCopy());
    }

    public function testOtherHandlersRunWithoutATransaction(): void
    {
        $log = $this->log;
        $this->dispatch(new PlainComponent($log)->add(...));
        $this->dispatch(static function () use ($log): void { $log[] = 'static'; });

        self::assertSame(['add', 'static'], $log->getArrayCopy());
    }

    public function testExceptionRollsBack(): void
    {
        try {
            $this->dispatch(new TransactionalComponent($this->log)->fail(...));
            self::fail('The handler exception is not thrown.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['begin', 'fail', 'rollback'], $this->log->getArrayCopy());
    }

    public function testResultOfATransactionalHandlerComesBack(): void
    {
        $result = $this->dispatch(new TransactionalComponent($this->log)->leave(...));

        self::assertSame(['begin', 'leave', 'commit'], $this->log->getArrayCopy());
        self::assertInstanceOf(RedirectResponse::class, $result);
        self::assertSame('/done', $result->getTargetUrl());
    }

    public function testTransactionalComponentNeedsARunner(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('install doctrine/orm');

        $this->dispatch(new TransactionalComponent($this->log)->add(...), withRunner: false);
    }

    private function dispatch(\Closure $handler, bool $withRunner = true): mixed
    {
        $runner = $withRunner ? new RecordingRunner($this->log) : null;

        return new HandlerInvoker([new TransactionalMiddleware($runner)])->invoke($handler, new Event('click'));
    }
}

#[Transactional]
final class TransactionalComponent
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly \ArrayObject $log)
    {
    }

    public function add(): void
    {
        $this->log[] = 'add';
    }

    public function leave(): RedirectResponse
    {
        $this->log[] = 'leave';

        return new RedirectResponse('/done');
    }

    public function fail(): void
    {
        $this->log[] = 'fail';
        throw new \RuntimeException('Failed.');
    }

    public function closure(): \Closure
    {
        return function (): void {
            $this->log[] = 'closure';
        };
    }
}

final class MethodTransactionalComponent
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly \ArrayObject $log)
    {
    }

    #[Transactional]
    public function save(): void
    {
        $this->log[] = 'save';
    }

    public function toggle(): void
    {
        $this->log[] = 'toggle';
    }

    public function closure(): \Closure
    {
        return function (): void {
            $this->log[] = 'closure';
        };
    }
}

final class PlainComponent
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly \ArrayObject $log)
    {
    }

    public function add(): void
    {
        $this->log[] = 'add';
    }
}

final class RecordingRunner implements TransactionRunner
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly \ArrayObject $log)
    {
    }

    public function run(\Closure $callback): mixed
    {
        $this->log[] = 'begin';

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->log[] = 'rollback';
            throw $e;
        }

        $this->log[] = 'commit';

        return $result;
    }
}
