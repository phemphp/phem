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

use Phem\Argument\CallableArguments;
use Phem\Event;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs an event handler through the HandlerMiddleware chain. The first middleware is the
 * outermost one. At the end of the chain, the handler gets its arguments as a Symfony
 * controller (see CallableArguments): the arguments of its call in the markup (see HandlerCall),
 * the event, the request, the services.
 *
 * The middlewares get the handler of a call, not the closure of the call: they read the
 * attributes and the object of the handler.
 */
final class HandlerInvoker
{
    /**
     * @param iterable<HandlerMiddleware> $middlewares
     */
    public function __construct(
        private readonly iterable $middlewares = [],
        private readonly CallableArguments $arguments = new CallableArguments(),
    ) {
    }

    /**
     * @param Request|null                                $request the request of the event (see Phem\Http\EventRequest)
     * @param \Closure(\Closure, list<mixed>): mixed|null $call    runs the handler with its arguments at the end of
     *                                                             the chain, in place of `$handler(...$arguments)`
     *                                                             (the work of an #[Async] handler, see
     *                                                             Phem\Async\AsyncHandler)
     */
    public function invoke(\Closure $handler, Event $event, ?Request $request = null, ?\Closure $call = null): mixed
    {
        [$handler, $bound] = HandlerCall::unwrap($handler);
        $call ??= static fn (\Closure $handler, array $arguments): mixed => $handler(...$arguments);
        // After the middlewares: a handler that a middleware stops resolves no arguments.
        $next = fn (): mixed => $call($handler, $this->arguments->of($handler, $request, $event, bound: $bound));
        $middlewares = $this->middlewares instanceof \Traversable ? iterator_to_array($this->middlewares, false) : $this->middlewares;

        foreach (array_reverse($middlewares) as $middleware) {
            $next = static fn (): mixed => $middleware->handle($handler, $event, $next);
        }

        return $next();
    }
}
