<?php

declare(strict_types=1);

namespace Mamu\Handler;

use Mamu\Event;

/**
 * Runs code around the event handlers (a transaction, a security check, a log entry).
 *
 * A service that implements this interface is a middleware (it is autoconfigured with the
 * `mamu.handler_middleware` tag). A middleware calls `$next()` to run the next middleware and
 * then the handler, or returns without a call to stop the event.
 */
interface HandlerMiddleware
{
    /**
     * @param \Closure          $handler the event handler. Its bound object is the component (a method, or a closure in `render()`).
     * @param \Closure(): mixed $next    runs the next middleware, then the handler
     *
     * @return mixed the result of the handler (for example a RedirectResponse)
     */
    public function handle(\Closure $handler, Event $event, \Closure $next): mixed;
}
