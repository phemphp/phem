<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Handler;

/**
 * Runs the event handlers of `#[Transactional]` components, or of all components with the
 * `mamu.transactional` option.
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
