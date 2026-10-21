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

namespace Phem\Handler;

use Phem\Event;
use Phem\Stream\Streams;

/**
 * Drops the streams of an event handler that throws (see Phem\Stream\Streams). It runs outside
 * the transaction, so a commit that fails drops them too: the browsers do not show a change that
 * is not in the database.
 */
final class StreamsMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly Streams $streams,
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $mark = $this->streams->mark();
        try {
            return $next();
        } catch (\Throwable $e) {
            $this->streams->rollback($mark);

            throw $e;
        }
    }
}
