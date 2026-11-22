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
use Phem\Reply;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs an event handler through the HandlerMiddleware chain. The first middleware is the
 * outermost one. At the end of the chain, the handler gets its arguments as a Symfony
 * controller (see CallableArguments): the arguments of its call in the markup (see HandlerCall),
 * the event, the reply, the request, the services.
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
     * @param Reply        $reply   the reply of this handler call (see Phem\Reply)
     * @param Request|null $request the request of the event (see Phem\Http\EventRequest)
     */
    public function invoke(\Closure $handler, Event $event, Reply $reply, ?Request $request = null): mixed
    {
        [$handler, $bound] = HandlerCall::unwrap($handler);
        // After the middlewares: a handler that a middleware stops resolves no arguments.
        $next = fn (): mixed => $handler(...$this->arguments->of($handler, $request, $event, $reply, bound: $bound));
        $middlewares = $this->middlewares instanceof \Traversable ? iterator_to_array($this->middlewares, false) : $this->middlewares;

        // The arguments of the call in the markup, for the middlewares that need them.
        $named = null;
        foreach (array_reverse($middlewares) as $middleware) {
            if ($middleware instanceof HandlerCallMiddleware) {
                $next = static function () use ($middleware, $handler, $bound, $event, $reply, $next, &$named): mixed {
                    $named ??= CallableArguments::call(new \ReflectionFunction($handler), $bound);

                    return $middleware->handleCall($handler, $named, $event, $reply, $next);
                };
                continue;
            }

            $next = static fn (): mixed => $middleware->handle($handler, $event, $reply, $next);
        }

        return $next();
    }
}
