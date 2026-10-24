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
 * `App\Page\Counter` → `app_page_counter`. The first route of a page also has the name of the
 * page as an alias: `$urlGenerator->generate(Counter::class)`, `path('App\\Page\\Counter')`, and
 * Phem\RedirectTo use it.
 *
 * The events of a page go to the URL of the page (`POST`, see EventController), so the access
 * rules of the page (`access_control`, the firewall, the host and the condition of the route)
 * apply to them too. Each page route has an event route (`phem_event.<name>`) with the same
 * path and the `POST` method. It comes first, so a page route without methods does not take
 * the events.
 *
 * A route value (`{id}`, or a `defaults` key) goes to the #[Path] property with the same name,
 * or to the parameter with the same name of a function page. A route value without one is an
 * error. The keys that start with `_` are not route values. As for the Doctrine controller
 * arguments, `{slug:post}` maps the value `slug` to the entity prop `post`.
 *
 * Import them in the application routes:
 *
 *     phem:
 *         resource: .
 *         type: phem
 */
final class PageLoader extends Loader
{
    /** The prefix of the event route names: `phem_event.app_page_counter`. */
    public const EVENT_ROUTE = 'phem_event';

    public function __construct(
        private readonly Manifest $manifest,
        ?string $env = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
        parent::__construct($env);
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

        foreach ($this->manifest->pages() as $page) {
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

    /** `App\Page\Counter` → `app_page_counter` (the Symfony convention for an invokable controller). */
    public static function routeName(string $page): string
    {
        return strtolower(str_replace('\\', '_', $page));
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

        foreach (array_unique([...$route->compile()->getVariables(), ...array_keys($route->getDefaults())]) as $value) {
            if (str_starts_with($value, '_')) {
                continue;
            }

            if (isset($mapping[$value])) {
                $prop = \is_array($mapping[$value]) ? $mapping[$value][0] : $mapping[$value];
                if (!\array_key_exists($prop, $paths)) {
                    throw new \InvalidArgumentException(\sprintf('The route "%s" maps the value "%s" to "%s", but the page "%s" has no %s "%s".', $name, $value, $prop, $page, $what, $prop));
                }

                $type = $paths[$prop];
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    throw new \InvalidArgumentException(\sprintf('The route "%s" maps the value "%s" to the %s "%s" of the page "%s", but its type is not an entity class.', $name, $value, $what, $prop, $page));
                }

                continue;
            }

            if (!\array_key_exists($value, $paths)) {
                throw new \InvalidArgumentException(\sprintf('The route "%s" has the value "%s", but the page "%s" has no %s "%s". Add one, or remove the value from the route.', $name, $value, $page, $what, $value));
            }
        }
    }

    /**
     * @param bool $alias true to add the name of the page as an alias of the route
     *
     * @return bool false when the route is not for this environment
     */
    private function addRoute(RouteCollection $routes, RouteAttribute $attr, string $page, int $index, bool $alias): bool
    {
        if ($attr->envs && !\in_array($this->env, $attr->envs, true)) {
            return false;
        }

        $name = $attr->name ?? self::routeName($page).($index > 0 ? '_'.$index : '');
        $defaults = array_replace($attr->defaults, ['_controller' => 'phem.controller.page', '_phem_page' => $page]);
        $paths = \is_array($attr->path) ? $attr->path : [0 => (string) $attr->path];

        foreach ($paths as $locale => $path) {
            $route = new Route($path, $defaults, $attr->requirements, $attr->options, $attr->host ?? '', $attr->schemes, $attr->methods, $attr->condition ?? '');
            $this->checkValues($route, $name, $page);

            $routeName = $name;
            if (0 !== $locale) {
                $route->setDefault('_locale', $locale);
                $route->setRequirement('_locale', preg_quote((string) $locale));
                $route->setDefault('_canonical_route', $name);
                $routeName .= '.'.$locale;
            }

            // The event route first: it takes the POST requests of the page URL.
            $event = (clone $route)
                ->setMethods(['POST'])
                ->setDefault('_controller', 'phem.controller.event')
                ->setDefault('_phem_methods', $route->getMethods());
            $routes->add(self::EVENT_ROUTE.'.'.$routeName, $event, $attr->priority ?? 0);
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
