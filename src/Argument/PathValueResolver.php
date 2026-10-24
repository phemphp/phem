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
 * Gives a route value of a function page to the parameter with its name. The value is already
 * converted to the type of the parameter (see Phem\Path\PathResolver), and it is in the signed
 * state of the page. It goes before the EntityValueResolver of Doctrine (priority 110).
 */
final class PathValueResolver implements ValueResolverInterface
{
    /** @return iterable<mixed> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $path = (array) $request->attributes->get(CallableArguments::PATH, []);
        if (!\array_key_exists($argument->getName(), $path)) {
            return [];
        }

        return [$path[$argument->getName()]];
    }
}
