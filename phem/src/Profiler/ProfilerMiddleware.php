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

use Phem\Event;
use Phem\Handler\HandlerMiddleware;

/**
 * Keeps the handler of an event in the Profile: its name, its time and its exception. It is
 * outside the transaction, so the time includes the commit.
 */
final class ProfilerMiddleware implements HandlerMiddleware
{
    public function __construct(private readonly Profile $profile)
    {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        return $this->profile->handler($handler, $next);
    }
}
