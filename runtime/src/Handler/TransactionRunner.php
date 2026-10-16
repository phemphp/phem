<?php

declare(strict_types=1);

namespace Rephp\Handler;

/**
 * Runs the event handlers of `#[Transactional]` components.
 */
interface TransactionRunner
{
    /**
     * Runs a callback in a transaction: commits when it returns, rolls back when it throws.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function run(\Closure $callback): mixed;
}
