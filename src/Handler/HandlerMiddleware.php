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

/**
 * Runs code around the event handlers (a transaction, a security check, a log entry).
 *
 * A service that implements this interface is a middleware (it is autoconfigured with the
 * `phem.handler_middleware` tag). A middleware calls `$next()` to run the next middleware and
 * then the handler, or returns without a call to stop the event.
 */
interface HandlerMiddleware
{
    /**
     * @param \Closure          $handler the event handler. Its bound object is the component (a method, or a closure in `render()`).
     * @param \Closure(): mixed $next    runs the next middleware, then the handler
     *
     * @return mixed the result of the handler (for example a Phem\RedirectTo)
     */
    public function handle(\Closure $handler, Event $event, \Closure $next): mixed;
}
