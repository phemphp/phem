<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Rephp\Server\TimerQueue;

final class TimerQueueTest extends TestCase
{
    private float $now = 100.0;

    private TimerQueue $timers;

    protected function setUp(): void
    {
        $this->timers = new TimerQueue(fn (): float => $this->now);
    }

    public function testTimersRunInDueOrder(): void
    {
        $order = [];
        $this->timers->add(3.0, static function () use (&$order): void { $order[] = 'c'; });
        $this->timers->add(1.0, static function () use (&$order): void { $order[] = 'a'; });
        $this->timers->add(2.0, static function () use (&$order): void { $order[] = 'b'; });
        $this->timers->add(1.0, static function () use (&$order): void { $order[] = 'a2'; });

        self::assertSame(1.0, $this->timers->nextDelay());

        $this->now += 2.0;
        $this->timers->runDue();
        self::assertSame(['a', 'a2', 'b'], $order);
        self::assertSame(1.0, $this->timers->nextDelay());

        $this->now += 5.0;
        $this->timers->runDue();
        self::assertSame(['a', 'a2', 'b', 'c'], $order);
        self::assertNull($this->timers->nextDelay());
        self::assertCount(0, $this->timers);
    }

    public function testCancelledTimerDoesNotRun(): void
    {
        $ran = false;
        $id = $this->timers->add(1.0, static function () use (&$ran): void { $ran = true; });
        $this->timers->add(5.0, static function (): void {});

        $this->timers->cancel($id);

        self::assertSame(5.0, $this->timers->nextDelay());
        $this->now += 10.0;
        $this->timers->runDue();
        self::assertFalse($ran);
    }

    public function testTimerAddedByCallbackRunsOnNextCall(): void
    {
        $runs = 0;
        $tick = function () use (&$runs, &$tick): void {
            ++$runs;
            $this->timers->add(0.0, $tick);
        };
        $this->timers->add(0.0, $tick);

        $this->timers->runDue();
        self::assertSame(1, $runs);

        $this->timers->runDue();
        self::assertSame(2, $runs);
    }

    public function testNextDelayIsZeroWhenOverdue(): void
    {
        $this->timers->add(1.0, static function (): void {});
        $this->now += 3.0;

        self::assertSame(0.0, $this->timers->nextDelay());
    }
}
