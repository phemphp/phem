<?php

declare(strict_types=1);

namespace Rephp\Routing;

use Rephp\Manifest;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The routes of the `#[Page]` components (from the rhpc manifest) and the event endpoint.
 *
 * Import them in the application routes:
 *
 *     rephp:
 *         resource: .
 *         type: rephp
 */
final class PageLoader extends Loader
{
    public const EVENT_ROUTE = 'rephp_event';

    public function __construct(private readonly Manifest $manifest, ?string $env = null)
    {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add(self::EVENT_ROUTE, new Route('/_rhp/event', ['_controller' => 'rephp.controller.event'], methods: ['POST']));

        // The router cache is rebuilt after the next `rhpc build`.
        $routes->addResource(new FileExistenceResource($this->manifest->file()));
        if (!$this->manifest->exists()) {
            return $routes;
        }
        $routes->addResource(new FileResource($this->manifest->file()));

        foreach ($this->manifest->routes() as $path => $page) {
            $routes->add(self::routeName($path), new Route($path, [
                '_controller' => 'rephp.controller.page',
                '_rephp_page' => $page,
                '_rephp_route' => $path,
            ], methods: ['GET', 'HEAD']));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'rephp' === $type;
    }

    /** `/` → `rephp_page_index`, `/examples/Counter` → `rephp_page_examples_counter`. */
    public static function routeName(string $path): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($path)), '_');

        return 'rephp_page_'.('' === $slug ? 'index' : $slug);
    }
}
