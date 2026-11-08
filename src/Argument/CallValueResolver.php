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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Gives an argument of a handler call in the markup (`onClick={{ pickReason(r) }}`, see
 * Phem\Handler\HandlerCall) to its parameter. The value comes from the render, as it is: an enum
 * case stays an enum case, and an entity stays an entity. It goes before the other resolvers, so
 * a parameter with the type Event or an entity class gets the value of the call.
 */
final class CallValueResolver implements ValueResolverInterface
{
    /** @return iterable<mixed> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $call = (array) $request->attributes->get(CallableArguments::CALL, []);
        if (!\array_key_exists($argument->getName(), $call)) {
            return [];
        }

        $value = $call[$argument->getName()];

        return $argument->isVariadic() ? (array) $value : [$value];
    }
}
