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

use Phem\Attribute\Async;
use Phem\Event;
use Phem\Handler\HandlerMiddleware;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs an #[Async] handler (see Phem\Attribute\Async): it dispatches the work of its Promise to a
 * worker (see AsyncDispatcher).
 *
 * It checks that a handler method with #[Async] returns a Promise, and that a handler method that
 * returns a Promise has #[Async]. A closure in `render()` that calls an #[Async] method
 * (`fn () => $this->ship($id)`) has no method of its own: its Promise is accepted.
 *
 * It runs inside the transaction (see `phem.handler_middleware.async`): a check or a dispatch that
 * fails rolls back the writes of the handler, and drops its pushes. With the Doctrine transport on
 * the connection of the entities, the message is part of the commit.
 */
final class AsyncMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly AsyncDispatcher $dispatcher,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $method = self::methodOf($handler);
        $async = null !== $method && [] !== $method->getAttributes(Async::class);

        if ($async) {
            // Before the handler: a failed check runs no handler.
            $this->dispatcher->check($this->requests?->getCurrentRequest());
        }

        $result = $next();

        if (null !== $method && $async && !$result instanceof Promise) {
            throw new \LogicException(\sprintf('The #[Async] handler "%s::%s()" must return a %s, "%s" given.', $method->class, $method->name, Promise::class, get_debug_type($result)));
        }

        if (null !== $method && !$async && $result instanceof Promise) {
            throw new \LogicException(\sprintf('The handler "%s::%s()" returns a %s, but it has no #[%s] attribute.', $method->class, $method->name, Promise::class, Async::class));
        }

        if ($result instanceof Promise) {
            $this->dispatcher->dispatch($this->requests?->getCurrentRequest());
        }

        return $result;
    }

    /** The method of a handler: a first-class callable of a method has its name, a closure does not. */
    private static function methodOf(\Closure $handler): ?\ReflectionMethod
    {
        $function = new \ReflectionFunction($handler);
        $class = $function->getClosureScopeClass();

        return null !== $class && $class->hasMethod($function->getName()) ? $class->getMethod($function->getName()) : null;
    }
}
