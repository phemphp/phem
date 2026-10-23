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

use Phem\Event;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\TransactionRunner;
use Phem\Profiler\Profile;
use Phem\Profiler\ProfiledTransactionRunner;
use Phem\Profiler\ProfilerMiddleware;
use Phem\Rendering\Fiber;
use Phem\Rendering\Tree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Stopwatch\Stopwatch;

final class ProfileTest extends TestCase
{
    public function testTheOwnTimeOfAFiberHasNoChildren(): void
    {
        $profile = new Profile();
        $tree = new Tree('App\Page');
        $tree->begin();
        $page = new Fiber('phem000000000001', '/App\Page#0', 'App\Page');
        $child = new Fiber('phem000000000002', '/App\Page#0/App\Child#0', 'App\Child');
        $child->parent = $page;

        $profile->profile($tree, $page, static function () use ($profile, $tree, $child): void {
            $profile->profile($tree, $child, static fn () => usleep(20000));
        });

        [$p, $c] = $profile->renders($tree);

        self::assertSame(['phem000000000001', 0, null], [$p['fiber'], $p['depth'], $p['parent']]);
        self::assertSame(['phem000000000002', 1, 'phem000000000001'], [$c['fiber'], $c['depth'], $c['parent']]);
        self::assertGreaterThanOrEqual(20.0, $c['time']);
        self::assertGreaterThanOrEqual($c['time'], $p['time']);
        self::assertLessThan(20.0, $p['own'], 'the page time has no time of its child');
        self::assertSame([$tree], $profile->trees());
    }

    public function testTheMiddlewareKeepsTheHandler(): void
    {
        $profile = new Profile($stopwatch = new Stopwatch());
        $component = new ProfiledComponent();

        $result = new HandlerInvoker([new ProfilerMiddleware($profile)])->invoke($component->save(...), Event::fromArray([]));

        self::assertSame('saved', $result);
        self::assertSame('ProfiledComponent::save()', $profile->handled()['name'] ?? null);
        self::assertSame(__FILE__, $profile->handled()['file'] ?? null);
        self::assertNull($profile->handled()['exception'] ?? null);
        self::assertTrue($stopwatch->isStarted('phem.handler') || [] !== $stopwatch->getEvent('phem.handler')->getPeriods());
    }

    public function testTheMiddlewareKeepsTheExceptionOfTheHandler(): void
    {
        $profile = new Profile();
        $component = new ProfiledComponent();

        try {
            new HandlerInvoker([new ProfilerMiddleware($profile)])->invoke($component->closure(), Event::fromArray([]));
            self::fail('The handler throws.');
        } catch (\DomainException) {
        }

        self::assertSame('ProfiledComponent closure', $profile->handled()['name'] ?? null);
        self::assertSame(\DomainException::class, $profile->handled()['exception'] ?? null);
    }

    public function testTheRunnerKeepsTheCommitAndTheRollback(): void
    {
        $profile = new Profile();
        $runner = new ProfiledTransactionRunner(new class implements TransactionRunner {
            public function run(\Closure $callback): mixed
            {
                return $callback();
            }
        }, $profile);

        $result = new \stdClass();
        self::assertSame($result, $runner->run(static fn (): object => $result));
        $commit = $profile->transacted();

        try {
            $runner->run(static fn () => throw new \RuntimeException());
        } catch (\RuntimeException) {
        }

        $rollback = $profile->transacted();

        self::assertTrue($commit['committed'] ?? null);
        self::assertFalse($rollback['committed'] ?? null);
    }

    public function testResetForgetsTheRequest(): void
    {
        $profile = new Profile();
        $tree = new Tree();
        $profile->profile($tree, new Fiber('phem000000000001', '/X#0', 'X'), static fn () => null);
        $profile->transaction(static fn () => null);

        $profile->reset();

        self::assertSame([], $profile->trees());
        self::assertSame([], $profile->renders($tree));
        self::assertNull($profile->transacted());
        self::assertNull($profile->handled());
    }
}

final class ProfiledComponent
{
    public function save(): string
    {
        return 'saved';
    }

    public function closure(): \Closure
    {
        return static fn () => throw new \DomainException();
    }
}
