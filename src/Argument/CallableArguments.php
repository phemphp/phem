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

namespace Phem\Argument;

use Phem\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

/**
 * The arguments of a function page or of an event handler, as for a Symfony controller: the
 * Symfony argument resolver gives the Request, the services, `#[CurrentUser]`,
 * `#[MapQueryParameter]` and the other controller arguments.
 *
 * Phem gives the arguments of a handler call in the markup (see CallValueResolver), the event of
 * a handler (see EventValueResolver), the route values of a function page (see
 * PathValueResolver), and the services of a function page (see ServiceValueResolver).
 * The services of a method come from the Symfony locator of its class component (see
 * Phem\DependencyInjection\ComponentPass). The values go in the attributes of a copy of the
 * request, so the request of the page does not change.
 */
final class CallableArguments
{
    /** The request attribute of the event of a handler. */
    public const EVENT = '_phem_event';

    /** The request attribute of the route values of a function page, by parameter name. */
    public const PATH = '_phem_path';

    /** The request attribute of the function page whose services the parameters get. */
    public const SERVICES = '_phem_services';

    /** The request attribute of the arguments of a handler call, by parameter name. */
    public const CALL = '_phem_call';

    private readonly ArgumentResolverInterface $resolver;

    /**
     * @param ArgumentResolverInterface|null $resolver the `argument_resolver` service, or the Phem
     *                                                 resolvers and the default Symfony resolvers
     */
    public function __construct(?ArgumentResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new ArgumentResolver(null, [new CallValueResolver(), new PathValueResolver(), new EventValueResolver(), ...ArgumentResolver::getDefaultArgumentValueResolvers()]);
    }

    /**
     * @param \Closure|callable-string $callable a handler, or the name of a function page
     * @param Request|null             $request  the request of the page or of the event, or null outside a request
     * @param array<string, mixed>     $path     the converted route values of a function page
     * @param list<mixed>              $bound    the arguments of a handler call in the markup (see Phem\Handler\HandlerCall)
     *
     * @return list<mixed>
     *
     * @throws \ArgumentCountError when the call has more arguments than the handler has parameters
     */
    public function of(\Closure|string $callable, ?Request $request = null, ?Event $event = null, array $path = [], array $bound = []): array
    {
        $function = new \ReflectionFunction($callable);
        $call = self::call($function, $bound);
        if (0 === $function->getNumberOfParameters()) {
            return [];
        }

        // The Symfony ServiceValueResolver finds the services of a method by `_controller`.
        $class = $function->getClosureScopeClass();
        $method = null !== $class && $class->hasMethod($function->getName()) ? $class->name.'::'.$function->getName() : null;

        $attributes = [
            '_controller' => $method,
            self::EVENT => $event,
            self::PATH => $path,
            self::SERVICES => \is_string($callable) ? ltrim($callable, '\\') : null,
            self::CALL => $call,
        ];

        $request = ($request ?? Request::create('/'))->duplicate(attributes: $attributes + ($request?->attributes->all() ?? []));

        return array_values($this->resolver->getArguments($request, $callable, $function));
    }

    /**
     * The arguments of a call by parameter name: the first parameters get them in order, and a
     * variadic parameter gets the rest as a list.
     *
     * @param list<mixed> $bound
     *
     * @return array<string, mixed>
     */
    private static function call(\ReflectionFunction $function, array $bound): array
    {
        $call = [];
        foreach ($function->getParameters() as $i => $parameter) {
            if (!\array_key_exists($i, $bound)) {
                break;
            }

            if ($parameter->isVariadic()) {
                $call[$parameter->getName()] = \array_slice($bound, $i);

                return $call;
            }

            $call[$parameter->getName()] = $bound[$i];
        }

        if (\count($call) < \count($bound)) {
            throw new \ArgumentCountError(\sprintf('The handler "%s" has %d parameters, but its call in the markup has %d arguments.', self::nameOf($function), $function->getNumberOfParameters(), \count($bound)));
        }

        return $call;
    }

    private static function nameOf(\ReflectionFunction $function): string
    {
        $class = $function->getClosureScopeClass();

        return null !== $class && $class->hasMethod($function->getName()) ? $class->name.'::'.$function->getName().'()' : $function->getName().'()';
    }
}
