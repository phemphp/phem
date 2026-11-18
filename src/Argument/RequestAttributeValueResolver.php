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
 * Gives an object that Phem makes for a handler call to a parameter with its class, at any
 * position: the Event (CallableArguments::EVENT) and the Reply (CallableArguments::REPLY). A
 * handler that does not need the object does not declare it.
 */
final class RequestAttributeValueResolver implements ValueResolverInterface
{
    /**
     * @param class-string $class     the class of the parameter
     * @param string       $attribute the request attribute that holds the object (see CallableArguments)
     */
    public function __construct(
        private readonly string $class,
        private readonly string $attribute,
    ) {
    }

    /** @return iterable<object> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $value = $request->attributes->get($this->attribute);
        if (!$value instanceof $this->class || $this->class !== $argument->getType()) {
            return [];
        }

        return [$value];
    }
}
