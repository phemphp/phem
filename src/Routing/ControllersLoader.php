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
 * The `routing.controllers` resource: the routes of the controllers, and the routes of the pages.
 *
 * The `config/routes.yaml` file of a Symfony application (7.4 and later) imports this resource,
 * so a page needs no import of its own, as a controller with a `#[Route]` attribute:
 *
 *     controllers:
 *         resource: routing.controllers
 *
 * It decorates the loader of Symfony. An application without this resource imports the pages
 * with the `phem` type (see PageLoader).
 */
final class ControllersLoader implements LoaderInterface
{
    public function __construct(
        private readonly LoaderInterface $controllers,
        private readonly PageLoader $pages,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = $this->controllers->load($resource, $type);
        if (!$routes instanceof RouteCollection) {
            throw new \LogicException(\sprintf('The "routing.controllers" loader returned "%s", not a RouteCollection.', get_debug_type($routes)));
        }

        $routes->addCollection($this->pages->load('.', 'phem'));

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $this->controllers->supports($resource, $type);
    }

    public function getResolver(): LoaderResolverInterface
    {
        return $this->controllers->getResolver();
    }

    public function setResolver(LoaderResolverInterface $resolver): void
    {
        $this->controllers->setResolver($resolver);
    }
}
