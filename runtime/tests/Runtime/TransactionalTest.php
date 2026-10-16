<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Attribute\Transactional;
use Rephp\Event;
use Rephp\Manifest;
use Rephp\Rendering\Renderer;
use Rephp\Rendering\TransactionRunner;
use Rephp\Rendering\Tree;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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
        self::assertTrue($this->dispatch(new TransactionalComponent($this->log)->add(...)));
        self::assertSame(['begin', 'add', 'commit'], $this->log->getArrayCopy());
    }

    public function testClosureOfATransactionalComponentRunsInATransaction(): void
    {
        $this->dispatch(new TransactionalComponent($this->log)->closure());
        self::assertSame(['begin', 'closure', 'commit'], $this->log->getArrayCopy());
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

    public function testRedirectOfATransactionalHandlerGoesToTheTree(): void
    {
        $tree = new Tree();
        $this->dispatch(new TransactionalComponent($this->log)->leave(...), tree: $tree);
        self::assertSame(['begin', 'leave', 'commit'], $this->log->getArrayCopy());
        self::assertSame('/done', $tree->redirect);
    }

    public function testTransactionalComponentNeedsARunner(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('install doctrine/orm');

        $this->dispatch(new TransactionalComponent($this->log)->add(...), withRunner: false);
    }

    private function dispatch(\Closure $handler, bool $withRunner = true, Tree $tree = new Tree()): bool
    {
        $runner = $withRunner ? new RecordingRunner($this->log) : null;
        $renderer = new Renderer(new Environment(new ArrayLoader()), new Manifest('/nonexistent', '/nonexistent'), new ServiceLocator([]), transactions: $runner);
        $id = $tree->addHandler($handler);

        return $renderer->dispatch($tree, $id, new Event('click'));
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
