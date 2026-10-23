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

    /**
     * @param \Closure(): mixed|null $call runs the handler at the end of the chain, in place of
     *                                     `$handler($event)` (the work of an #[Async] handler, see
     *                                     Phem\Async\AsyncHandler)
     */
    public function invoke(\Closure $handler, Event $event, ?\Closure $call = null): mixed
    {
        $next = $call ?? static fn (): mixed => $handler($event);
        $middlewares = $this->middlewares instanceof \Traversable ? iterator_to_array($this->middlewares, false) : $this->middlewares;
        foreach (array_reverse($middlewares) as $middleware) {
            $next = static fn (): mixed => $middleware->handle($handler, $event, $next);
        }

        return $next();
    }
}
