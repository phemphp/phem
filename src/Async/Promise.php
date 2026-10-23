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

namespace Phem\Async;

/**
 * The slow work of an #[Async] event handler (see Phem\Attribute\Async): a closure that runs
 * only in a Messenger worker, after the response.
 */
final class Promise
{
    /** @param \Closure(): void $work */
    public function __construct(
        public readonly \Closure $work,
    ) {
    }
}
