<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * One-shot timers ordered in a min-heap by due time.
 *
 * Cancelled timers stay in the heap and are dropped when they reach the top.
 */
final class TimerQueue implements \Countable
{
    /** @var \SplMinHeap<array{float, int}> entries of [due time, timer id] */
    private \SplMinHeap $heap;

    /** @var array<int, \Closure(): void> */
    private array $callbacks = [];

    private int $nextId = 1;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock returns monotonic time in seconds
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->heap = new \SplMinHeap();
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function now(): float
    {
        return ($this->clock)();
    }

    /**
     * @param \Closure(): void $callback
     */
    public function add(float $seconds, \Closure $callback): int
    {
        $id = $this->nextId++;
        $this->callbacks[$id] = $callback;
        // The id breaks ties, so timers with the same due time run in insertion order.
        $this->heap->insert([$this->now() + max(0.0, $seconds), $id]);

        return $id;
    }

    public function cancel(int $id): void
    {
        unset($this->callbacks[$id]);
    }

    /**
     * Seconds until the next timer is due (0 when one is already due), or null when there is no timer.
     */
    public function nextDelay(): ?float
    {
        $this->dropCancelled();

        if ($this->heap->isEmpty()) {
            return null;
        }

        return max(0.0, $this->heap->top()[0] - $this->now());
    }

    /**
     * Runs every due timer. Timers that the callbacks add run on a later call.
     */
    public function runDue(): void
    {
        $now = $this->now();
        $lastId = $this->nextId - 1;

        while (true) {
            $this->dropCancelled();

            if ($this->heap->isEmpty()) {
                return;
            }

            [$due, $id] = $this->heap->top();

            if ($due > $now || $id > $lastId) {
                return;
            }

            $this->heap->extract();
            $callback = $this->callbacks[$id];
            unset($this->callbacks[$id]);
            $callback();
        }
    }

    public function count(): int
    {
        return \count($this->callbacks);
    }

    private function dropCancelled(): void
    {
        while (!$this->heap->isEmpty() && !isset($this->callbacks[$this->heap->top()[1]])) {
            $this->heap->extract();
        }
    }
}
