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

use Phem\Manifest\Manifest;
use Phem\Metadata\ComponentMetadata;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The last step of the routes of the application: the routes of the pages, after the imports
 * changed them (a prefix, a host, a localized prefix or host, a name prefix). See
 * PageRoutesLoader.
 *
 * A route of a route file whose controller is a page is a page route, as a `#[Page]` route:
 *
 *     blog:
 *         path: /blog/{page}
 *         controller: App\Ui\BlogPage
 *
 * The controller can be a parameter (`'%app.blog_page%'`). A page with only such routes gets the
 * name of the page as an alias of its first route, as the first `#[Page]` route (see
 * PageLoader). A page of the application with a `#[Page]` with no path needs such a route.
 *
 * Each route value of such a route needs a #[Path] property or a parameter (see
 * PageRouteCheck). PageLoader checks the `#[Page]` routes before the imports.
 *
 * The events of a page go to the URL of the page (`POST`, see EventController), so the access
 * rules of the page (`access_control`, the firewall, the host and the condition of the route)
 * apply to them too. Each page route gets an event route (`phem_event.<name>`) with the same
 * path and the `POST` method. It comes first, so a page route without methods does not take
 * the events. A request that it matches has the name of the page route (`_route`), so the
 * access rules on the route name (`access_control: [{route: ...}]`) apply to the events too.
 */
final class PageRoutes
{
    /** The prefix of the event route names: `phem_event.app_page_counter`. */
    public const EVENT_ROUTE = 'phem_event';

    private const PAGE_CONTROLLER = 'phem.controller.page';

    private readonly PageRouteCheck $check;

    public function __construct(
        private readonly Manifest $manifest,
        ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ?ParameterBagInterface $parameters = null,
    ) {
        $this->check = new PageRouteCheck($metadata);
    }

    /**
     * The routes, with an event route before each page route.
     *
     * @throws \InvalidArgumentException when a route value of a route file has no #[Path] property
     *                                   or parameter, or a page with no path has no route
     */
    public function complete(RouteCollection $routes): RouteCollection
    {
        $pages = array_fill_keys($this->manifest->exists() ? $this->manifest->pages() : [], true);
        $aliased = self::aliased($routes, $pages);

        $out = new RouteCollection();
        foreach ($routes->getResources() as $resource) {
            $out->addResource($resource);
        }

        // The canonical name of the first route of each page with no alias.
        $first = [];
        foreach ($routes->all() as $name => $route) {
            $priority = $routes->getPriority($name) ?? 0;
            $page = $this->pageOf($route, $name, $pages);

            // A route that is not a page route, or a page route with its event route already.
            if (null === $page || null !== $routes->get(self::EVENT_ROUTE.'.'.$name)) {
                $out->add($name, $route, $priority);
                continue;
            }

            $canonical = $route->getDefault('_canonical_route');
            $canonical = \is_string($canonical) ? $canonical : $name;

            $out->add(self::EVENT_ROUTE.'.'.$name, self::eventRoute($route, $canonical), $priority);
            $out->add($name, $route, $priority);

            if (!isset($aliased[$page]) && $canonical === ($first[$page] ??= $canonical)) {
                $locale = $route->getDefault('_locale');
                $out->addAlias($canonical !== $name && \is_string($locale) ? $page.'.'.$locale : $page, $name);
            }
        }

        self::checkRouted($out, $this->manifest->exists() ? $this->manifest->pagesOf('') : []);

        foreach ($routes->getAliases() as $name => $alias) {
            $added = $out->addAlias($name, $alias->getId());

            // The template of the message has the placeholder.
            if ($alias->isDeprecated()) {
                $deprecation = $alias->getDeprecation('%alias_id%');
                $added->setDeprecated($deprecation['package'], $deprecation['version'], $deprecation['message']);
            }
        }

        return $out;
    }

    /**
     * The page of a route: the `_phem_page` of a `#[Page]` route, or the controller of a route
     * of a route file when it is a page. Such a route gets the controller of the pages, and its
     * route values are checked.
     *
     * @param array<string, true> $pages
     */
    private function pageOf(Route $route, string $name, array $pages): ?string
    {
        $controller = $route->getDefault('_controller');
        $page = $route->getDefault('_phem_page');
        if (self::PAGE_CONTROLLER === $controller) {
            return \is_string($page) ? $page : null;
        }

        // The router resolves the parameters after the loader.
        if (\is_string($controller) && str_contains($controller, '%') && null !== $this->parameters) {
            $controller = $this->parameters->resolveValue($controller);
        }

        if (!\is_string($controller) || !isset($pages[ltrim($controller, '\\')])) {
            return null;
        }

        $page = ltrim($controller, '\\');
        $route->addDefaults(['_controller' => self::PAGE_CONTROLLER, '_phem_page' => $page]);
        $this->check->check($route, $name, $page);

        return $page;
    }

    /**
     * A page of the application with a `#[Page]` with no path, and no route: it is not reachable,
     * and `path()` of the page fails.
     *
     * @param list<string> $pages the pages of the application
     */
    private static function checkRouted(RouteCollection $routes, array $pages): void
    {
        $routed = [];
        foreach ($routes->all() as $route) {
            $page = $route->getDefault('_phem_page');
            if (\is_string($page)) {
                $routed[$page] = true;
            }
        }

        foreach (array_diff($pages, array_keys($routed)) as $page) {
            $reflection = match (true) {
                class_exists($page) => new \ReflectionClass($page),
                \function_exists($page) => new \ReflectionFunction($page),
                default => null,
            };

            foreach ($reflection?->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) ?? [] as $attribute) {
                $path = $attribute->newInstance()->path;
                if (null === $path || [] === $path) {
                    throw new \InvalidArgumentException(\sprintf('The page "%s" has a #[Page] with no path, and no route of the application routes it. Add a route with the controller "%s" to a route file, or give the #[Page] a path.', $page, $page));
                }
            }
        }
    }

    /**
     * The pages with an alias: the name of the page (`App\Ui\BlogPage`), or the name of the page
     * with a locale (`App\Ui\BlogPage.en`).
     *
     * @param array<string, true> $pages
     *
     * @return array<string, true>
     */
    private static function aliased(RouteCollection $routes, array $pages): array
    {
        $aliased = [];
        foreach (array_keys($routes->getAliases()) as $name) {
            $page = explode('.', $name, 2)[0];
            if (isset($pages[$page])) {
                $aliased[$page] = true;
            }
        }

        return $aliased;
    }

    private static function eventRoute(Route $route, string $canonical): Route
    {
        return (clone $route)
            ->setMethods(['POST'])
            ->setDefault('_controller', 'phem.controller.event')
            ->setDefault('_phem_methods', $route->getMethods())
            ->setDefault('_canonical_route', $canonical);
    }
}
