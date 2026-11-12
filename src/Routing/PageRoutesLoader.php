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

namespace Phem\Routing;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Decorates the `routing.loader` service: the loader of the router, which loads all the routes
 * of the application one time. The imports of the route files use the loaders of the resolver,
 * so PageRoutes sees the routes after all the imports.
 */
final class PageRoutesLoader implements LoaderInterface
{
    public function __construct(
        private readonly LoaderInterface $routes,
        private readonly PageRoutes $pages,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): mixed
    {
        $routes = $this->routes->load($resource, $type);

        return $routes instanceof RouteCollection ? $this->pages->complete($routes) : $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $this->routes->supports($resource, $type);
    }

    public function getResolver(): LoaderResolverInterface
    {
        return $this->routes->getResolver();
    }

    public function setResolver(LoaderResolverInterface $resolver): void
    {
        $this->routes->setResolver($resolver);
    }
}
