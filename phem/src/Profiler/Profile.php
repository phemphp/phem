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

namespace Phem\Profiler;

use Phem\Rendering\Fiber;
use Phem\Rendering\RenderProfiler;
use Phem\Rendering\Tree;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps what Phem did in one request, for the profiler panel (see PhemDataCollector): the
 * render of each fiber in each pass, the event handler, its transaction, and the pushed streams.
 *
 * Phem uses it only in debug mode. With the Stopwatch, the render passes, the handler and the
 * transaction also show in the Performance panel of the profiler.
 */
final class Profile implements RenderProfiler, ResetInterface
{
    /** @var array<int, Tree> the trees, in the order of their first render */
    private array $trees = [];
    /** @var list<array{tree: int, pass: int, depth: int, fiber: string, fqcn: string, parent: string|null, time: float, own: float}> */
    private array $renders = [];
    /** @var list<array{int, float}> the renders that run: [their index in the renders, the time of their children] */
    private array $stack = [];
    /** @var array{name: string, file: string|null, line: int|null, time: float, exception: string|null}|null */
    private ?array $handler = null;
    /** @var array{committed: bool, time: float}|null */
    private ?array $transaction = null;
    /** @var list<array{topics: list<string>, data: string, private: bool}> the published updates (see ProfiledStreamPublisher) */
    private array $streams = [];

    public function __construct(private readonly ?Stopwatch $stopwatch = null)
    {
    }

    public function profile(Tree $tree, Fiber $fiber, \Closure $render): void
    {
        $id = spl_object_id($tree);
        $this->trees[$id] ??= $tree;
        $index = \count($this->renders);
        $this->renders[] = ['tree' => $id, 'pass' => $tree->pass, 'depth' => \count($this->stack), 'fiber' => $fiber->id, 'fqcn' => $fiber->fqcn, 'parent' => $fiber->parent?->id, 'time' => 0.0, 'own' => 0.0];
        $this->stack[] = [$index, 0.0];
        $outer = 1 === \count($this->stack);
        if ($outer) {
            $this->stopwatch?->start('phem.render', 'phem');
        }
        $start = hrtime(true);
        try {
            $render();
        } finally {
            $time = (hrtime(true) - $start) / 1e6;
            $children = array_pop($this->stack)[1] ?? 0.0;
            $record = $this->renders[$index];
            $record['time'] = $time;
            $record['own'] = max(0.0, $time - $children);
            $this->renders[$index] = $record;
            if ([] !== $this->stack) {
                $this->stack[\count($this->stack) - 1][1] += $time;
            }
            if ($outer) {
                $this->stopwatch?->stop('phem.render');
            }
        }
    }

    /**
     * Runs an event handler (see ProfilerMiddleware), and keeps its time and its exception.
     *
     * @param \Closure(): mixed $next
     */
    public function handler(\Closure $handler, \Closure $next): mixed
    {
        $function = new \ReflectionFunction($handler);
        $record = ['name' => self::nameOf($function), 'file' => $function->getFileName() ?: null, 'line' => $function->getStartLine() ?: null, 'time' => 0.0, 'exception' => null];
        $this->handler = $record;
        $this->stopwatch?->start('phem.handler', 'phem');
        $start = hrtime(true);
        try {
            return $next();
        } catch (\Throwable $e) {
            $record['exception'] = $e::class;

            throw $e;
        } finally {
            $record['time'] = (hrtime(true) - $start) / 1e6;
            $this->handler = $record;
            $this->stopwatch?->stop('phem.handler');
        }
    }

    /**
     * Runs a transaction (see ProfiledTransactionRunner), and keeps its time and its end: a
     * commit, or a rollback when the callback throws.
     *
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    public function transaction(\Closure $run): mixed
    {
        $committed = false;
        $this->transaction = ['committed' => false, 'time' => 0.0];
        $this->stopwatch?->start('phem.transaction', 'phem');
        $start = hrtime(true);
        try {
            $result = $run();
            $committed = true;

            return $result;
        } finally {
            $this->transaction = ['committed' => $committed, 'time' => (hrtime(true) - $start) / 1e6];
            $this->stopwatch?->stop('phem.transaction');
        }
    }

    /**
     * Keeps an update that Phem published to the Mercure hub (see Phem\Stream\Streams).
     *
     * @param list<string> $topics
     */
    public function published(array $topics, string $data, bool $private): void
    {
        $this->streams[] = ['topics' => $topics, 'data' => $data, 'private' => $private];
    }

    /** @return list<array{topics: list<string>, data: string, private: bool}> */
    public function streams(): array
    {
        return $this->streams;
    }

    /** @return list<Tree> the trees that rendered, in their order */
    public function trees(): array
    {
        return array_values($this->trees);
    }

    /**
     * The renders of a tree, in their order. The time is in milliseconds: `time` with the
     * children, `own` without them. The depth is 0 for a fiber that renders first in its pass
     * (the page, or a fiber that renders again after an event).
     *
     * @return list<array{pass: int, depth: int, fiber: string, fqcn: string, parent: string|null, time: float, own: float}>
     */
    public function renders(Tree $tree): array
    {
        $id = spl_object_id($tree);
        $out = [];
        foreach ($this->renders as $render) {
            if ($render['tree'] === $id) {
                unset($render['tree']);
                $out[] = $render;
            }
        }

        return $out;
    }

    /** @return array{name: string, file: string|null, line: int|null, time: float, exception: string|null}|null */
    public function handled(): ?array
    {
        return $this->handler;
    }

    /** @return array{committed: bool, time: float}|null */
    public function transacted(): ?array
    {
        return $this->transaction;
    }

    public function reset(): void
    {
        $this->trees = [];
        $this->renders = [];
        $this->stack = [];
        $this->handler = null;
        $this->transaction = null;
        $this->streams = [];
    }

    /** `Counter::add()`, or `Counter closure` for a closure in render(). */
    private static function nameOf(\ReflectionFunction $function): string
    {
        $class = $function->getClosureScopeClass()?->getShortName();
        $closure = str_starts_with($function->getName(), '{closure');
        $name = $closure ? 'closure' : $function->getName().'()';

        return null !== $class ? $class.($closure ? ' ' : '::').$name : $name;
    }
}
