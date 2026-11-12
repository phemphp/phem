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

use Composer\InstalledVersions;
use Phem\Manifest\Manifest;
use Phem\Metadata\ComponentMetadata;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Attribute\DeprecatedAlias;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The routes of the pages and the event endpoint.
 *
 * A page is a component with the `#[Page]` attribute: a class, or a function (a class component
 * can also have the Symfony `#[Route]` attribute; phemc lists them in the manifest). It renders
 * its document, with a `<Root>` (see Phem\Root). The attribute works as on a controller: path
 * (or localized paths), name, requirements, defaults, methods, host, schemes, condition,
 * priority, env and aliases. Without a name, the route name comes from the component:
 * `App\Ui\CounterPage` → `app_ui_counterpage`. The first route of a page also has the name of the
 * page as an alias: `$urlGenerator->generate(CounterPage::class)`, `path('App\\Ui\\CounterPage')`, and
 * Phem\RedirectTo use it. A `#[Page]` with no path has no route here: its routes are in the
 * route files of the application (see PageRoutes).
 *
 * A route value (`{id}`, or a `defaults` key) goes to the #[Path] property with the same name,
 * or to the parameter with the same name of a function page. As for the parameters of a
 * controller, the default value of the prop or the parameter makes its route value optional
 * (see PageRouteDefaults).
 *
 * The event routes of the pages, and the check of the route values, come after the imports, on
 * the routes of the application (see PageRoutes).
 *
 * The `routing.controllers` resource of the application routes has the pages of the application
 * (see ControllersLoader). An application without this resource imports them:
 *
 *     phem:
 *         resource: .
 *         type: phem
 *
 * The pages of a Composer package are not in this resource. The application imports them with
 * the name of the package, for example with a prefix:
 *
 *     acme_ui:
 *         resource: acme/ui
 *         type: phem
 *         prefix: /ui
 */
final class PageLoader extends Loader
{
    private readonly PageRouteDefaults $defaults;

    public function __construct(
        private readonly Manifest $manifest,
        ?string $env = null,
        ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
        parent::__construct($env);

        $this->defaults = new PageRouteDefaults($metadata);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        // The router cache is rebuilt after the next `phemc build`.
        $routes->addResource(new FileExistenceResource($this->manifest->file()));
        if (!$this->manifest->exists()) {
            return $routes;
        }

        $routes->addResource(new FileResource($this->manifest->file()));

        foreach ($this->pages((string) $resource) as $page) {
            $reflection = match (true) {
                class_exists($page, false) => new \ReflectionClass($page),
                \function_exists($page) => new \ReflectionFunction($page),
                default => throw new \InvalidArgumentException(\sprintf('The page "%s" is not loaded. Run `phemc build` again.', $page)),
            };
            // A route change does not change the manifest, only the compiled file.
            $routes->addResource(new FileResource((string) $reflection->getFileName()));

            // The first route of the page has its name as an alias (see PageUrlGenerator).
            $aliased = false;
            foreach ($reflection->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $i => $attribute) {
                $aliased = $this->addRoute($routes, $attribute->newInstance(), $page, $i, !$aliased) || $aliased;
            }
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'phem' === $type;
    }

    /** `App\Ui\CounterPage` → `app_ui_counterpage` (the Symfony convention for an invokable controller). */
    public static function routeName(string $page): string
    {
        return strtolower(str_replace('\\', '_', $page));
    }

    /**
     * The pages of a resource: `.` for the pages of the application, or the name of a Composer
     * package (`acme/ui`) for the pages of this package.
     *
     * @return list<string>
     */
    private function pages(string $resource): array
    {
        if ('.' === $resource) {
            return $this->manifest->pagesOf('');
        }

        $pages = $this->manifest->pagesOf($resource);

        // An installed package with no pages in the build: the build is older than the package
        // (after `composer require`, before `phemc build`). Its routes come with the next build,
        // because the manifest is a resource of the routes.
        if ([] === $pages && !(class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($resource))) {
            $packages = $this->manifest->pagePackages();

            throw new \InvalidArgumentException(\sprintf('The package "%s" is not installed, and it has no pages in the build. The packages with pages: %s.', $resource, [] !== $packages ? '"'.implode('", "', $packages).'"' : 'none'));
        }

        return $pages;
    }

    /**
     * @param bool $alias true to add the name of the page as an alias of the route
     *
     * @return bool false when the route is not for this environment, or has no path
     */
    private function addRoute(RouteCollection $routes, RouteAttribute $attr, string $page, int $index, bool $alias): bool
    {
        if ($attr->envs && !\in_array($this->env, $attr->envs, true)) {
            return false;
        }

        // A page whose routes are in the route files of the application.
        if (null === $attr->path) {
            return false;
        }

        $name = $attr->name ?? self::routeName($page).($index > 0 ? '_'.$index : '');

        foreach ($attr->requirements as $placeholder => $requirement) {
            if (\is_int($placeholder)) {
                throw new \InvalidArgumentException(\sprintf('A placeholder name must be a string (%d given). Did you forget to specify the placeholder key for the requirement "%s" of the route "%s" of the page "%s"?', $placeholder, $requirement, $name, $page));
            }
        }

        $paths = \is_array($attr->path) ? $attr->path : [0 => (string) $attr->path];
        $defaults = array_replace($this->defaults->of($attr->defaults, $paths, $attr->requirements, $page), ['_controller' => 'phem.controller.page', '_phem_page' => $page]);

        foreach ($paths as $locale => $path) {
            $route = new Route($path, $defaults, $attr->requirements, $attr->options, $attr->host ?? '', $attr->schemes, $attr->methods, $attr->condition ?? '');

            $routeName = $name;
            if (0 !== $locale) {
                $route->setDefault('_locale', $locale);
                $route->setRequirement('_locale', preg_quote((string) $locale));
                $route->setDefault('_canonical_route', $name);
                $routeName .= '.'.$locale;
            }

            $routes->add($routeName, $route, $attr->priority ?? 0);
        }

        foreach ($alias ? [$page, ...$attr->aliases] : $attr->aliases as $aliasOf) {
            $aliasName = $aliasOf instanceof DeprecatedAlias ? $aliasOf->aliasName : $aliasOf;
            foreach (array_keys($paths) as $locale) {
                $suffix = 0 !== $locale ? '.'.$locale : '';
                $added = $routes->addAlias($aliasName.$suffix, $name.$suffix);
                if ($aliasOf instanceof DeprecatedAlias) {
                    $added->setDeprecated($aliasOf->package, $aliasOf->version, $aliasOf->message);
                }
            }
        }

        return true;
    }
}
