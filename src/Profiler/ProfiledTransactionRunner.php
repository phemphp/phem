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

use Phem\Handler\TransactionRunner;

/**
 * Keeps the transaction of an event handler in the Profile: its time, and a commit or a rollback.
 */
final class ProfiledTransactionRunner implements TransactionRunner
{
    public function __construct(
        private readonly TransactionRunner $inner,
        private readonly Profile $profile,
    ) {
    }

    public function run(\Closure $callback): mixed
    {
        return $this->profile->transaction(fn (): mixed => $this->inner->run($callback));
    }
}
