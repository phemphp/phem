<?php

declare(strict_types=1);

namespace Mamu\Handler;

use Mamu\Event;

/**
 * Runs an event handler through the HandlerMiddleware chain. The first middleware is the
 * outermost one.
 */
final class HandlerInvoker
{
    /**
     * @param iterable<HandlerMiddleware> $middlewares
     */
    public function __construct(
        private readonly iterable $middlewares = [],
    ) {
    }

    public function invoke(\Closure $handler, Event $event): mixed
    {
        $next = static fn (): mixed => $handler($event);
        $middlewares = $this->middlewares instanceof \Traversable ? iterator_to_array($this->middlewares, false) : $this->middlewares;
        foreach (array_reverse($middlewares) as $middleware) {
            $next = static fn (): mixed => $middleware->handle($handler, $event, $next);
        }

        return $next();
    }
}
