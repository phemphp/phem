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

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NearMissValueResolverException;

/**
 * Gives a service to a parameter of a function page, as the Symfony ServiceValueResolver does
 * for a controller method. ComponentPass makes a locator for each function page.
 */
final class ServiceValueResolver implements ValueResolverInterface
{
    /**
     * @param ContainerInterface $pages function page => the locator of its parameters
     */
    public function __construct(private readonly ContainerInterface $pages)
    {
    }

    /** @return iterable<mixed> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $page = $request->attributes->get(CallableArguments::SERVICES);
        if (!\is_string($page) || !$this->pages->has($page)) {
            return [];
        }

        $services = $this->pages->get($page);
        if (!$services instanceof ContainerInterface || !$services->has($argument->getName())) {
            return [];
        }

        try {
            return [$services->get($argument->getName())];
        } catch (RuntimeException $e) {
            throw new NearMissValueResolverException(\sprintf('Cannot resolve the argument $%s of the page "%s()": %s', $argument->getName(), $page, $e->getMessage()), $e->getCode(), $e);
        }
    }
}
