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
 * A page with only such routes gets the name of the page as an alias of its first route, as the
 * first `#[Page]` route (see PageLoader).
 *
 * A route value (`{id}`, or a `defaults` key) needs the #[Path] property with the same name, or
 * the parameter with the same name of a function page. The keys that start with `_` are not
 * route values. As for the Doctrine controller arguments, `{slug:post}` maps the value `slug` to
 * the entity prop `post`.
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

    public function __construct(
        private readonly Manifest $manifest,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    /**
     * The routes, with an event route before each page route.
     *
     * @throws \InvalidArgumentException when a route value has no #[Path] property or parameter
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
            $page = self::pageOf($route, $pages);

            // A route that is not a page route, or a page route with its event route already.
            if (null === $page || null !== $routes->get(self::EVENT_ROUTE.'.'.$name)) {
                $out->add($name, $route, $priority);
                continue;
            }

            $this->checkValues($route, $name, $page);

            $canonical = $route->getDefault('_canonical_route');
            $canonical = \is_string($canonical) ? $canonical : $name;

            $out->add(self::EVENT_ROUTE.'.'.$name, self::eventRoute($route, $canonical), $priority);
            $out->add($name, $route, $priority);

            if (!isset($aliased[$page]) && $canonical === ($first[$page] ??= $canonical)) {
                $locale = $route->getDefault('_locale');
                $out->addAlias($canonical !== $name && \is_string($locale) ? $page.'.'.$locale : $page, $name);
            }
        }

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
     * of a route file when it is a page. Such a route gets the controller of the pages.
     *
     * @param array<string, true> $pages
     */
    private static function pageOf(Route $route, array $pages): ?string
    {
        $controller = $route->getDefault('_controller');
        $page = $route->getDefault('_phem_page');
        if (self::PAGE_CONTROLLER === $controller) {
            return \is_string($page) ? $page : null;
        }

        if (!\is_string($controller) || !isset($pages[ltrim($controller, '\\')])) {
            return null;
        }

        $page = ltrim($controller, '\\');
        $route->addDefaults(['_controller' => self::PAGE_CONTROLLER, '_phem_page' => $page]);

        return $page;
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

    /**
     * Each route value needs a #[Path] property (a parameter of a function page). A mapped value
     * (`{slug:post}`) needs one with an entity type.
     */
    private function checkValues(Route $route, string $name, string $page): void
    {
        $paths = $this->metadata->pathProps($page);
        $what = class_exists($page) ? '#[Path] property' : 'parameter';
        $mapping = (array) ($route->getDefault('_route_mapping') ?? []);

        foreach (PageRouteValues::props($route) as $value => $prop) {
            if (isset($mapping[$value])) {
                if (!\array_key_exists($prop, $paths)) {
                    throw new \InvalidArgumentException(\sprintf('The route "%s" maps the value "%s" to "%s", but the page "%s" has no %s "%s".', $name, $value, $prop, $page, $what, $prop));
                }

                $type = $paths[$prop];
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    throw new \InvalidArgumentException(\sprintf('The route "%s" maps the value "%s" to the %s "%s" of the page "%s", but its type is not an entity class.', $name, $value, $what, $prop, $page));
                }

                continue;
            }

            if (!\array_key_exists($prop, $paths)) {
                throw new \InvalidArgumentException(\sprintf('The route "%s" has the value "%s", but the page "%s" has no %s "%s". Add one, or remove the value from the route.', $name, $value, $page, $what, $value));
            }
        }
    }
}
