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

/**
 * A handler call with arguments in the markup: `onClick={{ pickReason(r) }}`
 * (`phem_call(pickReason, [r])`). The call does not run during the render: it gives a closure
 * that keeps the handler and the arguments.
 *
 * The arguments are values of the render, not of the browser. The handler id has the fingerprint
 * of the arguments (see HandlerFingerprint), and the event renders the path to the handler again
 * to find it. Thus the browser sends only the id, and it cannot change an argument: an id with
 * other arguments is not found (409).
 *
 * The closure has the object of the handler, so the owner of the handler stays the same (see
 * Phem\Rendering\EventRenderer). The arguments go to the first parameters of the handler. The
 * other parameters get the event, the services and the other controller arguments (see
 * HandlerInvoker). A call of a call adds its arguments after the arguments of the first call.
 */
final class HandlerCall
{
    /**
     * @param list<mixed> $arguments
     *
     * @return \Closure|null null for no handler (an optional prop), as `onClick={{ null }}`
     */
    public static function bind(mixed $handler, array $arguments): ?\Closure
    {
        if (null === $handler || false === $handler) {
            return null;
        }

        // Only a Closure, as phem_on(): a string or an array can also be callable (`'system'`).
        if (!$handler instanceof \Closure) {
            throw new \InvalidArgumentException(\sprintf('A handler call with arguments needs a function, "%s" given.', get_debug_type($handler)));
        }

        [$target, $bound] = self::unwrap($handler);
        $arguments = [...$bound, ...$arguments];
        $call = function (mixed ...$rest) use ($target, $arguments): mixed {
            return $target(...$arguments, ...$rest);
        };

        $bindTo = new \ReflectionFunction($target)->getClosureThis();

        return \Closure::bind($call, $bindTo, self::class);
    }

    /**
     * The handler and the arguments of a call. A handler with no call has no arguments.
     *
     * @return array{\Closure, list<mixed>}
     */
    public static function unwrap(\Closure $handler): array
    {
        $function = new \ReflectionFunction($handler);
        if (self::class !== $function->getClosureScopeClass()?->name) {
            return [$handler, []];
        }

        $used = $function->getClosureUsedVariables();

        return [$used['target'], $used['arguments']];
    }
}
