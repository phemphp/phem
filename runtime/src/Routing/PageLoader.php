<?php

declare(strict_types=1);

namespace Rephp\Routing;

use Rephp\Manifest;
use Rephp\Rendering\PropBinder;
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
 * A page is a component with the Symfony `#[Route]` attribute (rhpc lists them in the
 * manifest). The attribute works as on a controller: path (or localized paths), name,
 * requirements, defaults, methods, host, schemes, condition, priority, env and aliases.
 * Without a name, the route name comes from the component: `App\Pages\Counter` →
 * `app_pages_counter`.
 *
 * A route value (`{id}`, or a `defaults` key) goes to the #[Path] property or parameter with
 * the same name. A route value without one is an error. The keys that start with `_` are not
 * route values. As for the Doctrine controller arguments, `{slug:post}` maps the value `slug`
 * to the entity prop `post`.
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

    public function __construct(
        private readonly Manifest $manifest,
        ?string $env = null,
        private readonly PropBinder $props = new PropBinder(),
    ) {
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

        foreach ($this->manifest->pages() as $page) {
            $reflection = class_exists($page, false) ? new \ReflectionClass($page) : new \ReflectionFunction($page);
            // A route change does not change the manifest, only the compiled file.
            $routes->addResource(new FileResource((string) $reflection->getFileName()));
            foreach ($reflection->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $i => $attribute) {
                // Not newInstance(): the attribute allows only classes and methods, not functions.
                $class = $attribute->getName();
                $this->addRoute($routes, new $class(...$attribute->getArguments()), $page, $i);
            }
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'rephp' === $type;
    }

    /** `App\Pages\Counter` → `app_pages_counter` (the Symfony convention for an invokable controller). */
    public static function routeName(string $page): string
    {
        return strtolower(str_replace('\\', '_', $page));
    }

    /**
     * Each route value needs a #[Path] property or parameter. A mapped value (`{slug:post}`)
     * needs a #[Path] prop with an entity type.
     */
    private function checkValues(Route $route, string $name, string $page): void
    {
        $paths = $this->props->pathProps($page);
        $kind = class_exists($page, false) ? 'property' : 'parameter';
        $mapping = (array) ($route->getDefault('_route_mapping') ?? []);
        foreach (array_unique([...$route->compile()->getVariables(), ...array_keys($route->getDefaults())]) as $value) {
            if (str_starts_with($value, '_')) {
                continue;
            }
            if (isset($mapping[$value])) {
                $prop = \is_array($mapping[$value]) ? $mapping[$value][0] : $mapping[$value];
                if (!\array_key_exists($prop, $paths)) {
                    throw new \InvalidArgumentException(\sprintf('The route "%s" maps the value "%s" to "%s", but the page "%s" has no #[Path] %s "%s".', $name, $value, $prop, $page, $kind, $prop));
                }
                $type = $paths[$prop];
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    throw new \InvalidArgumentException(\sprintf('The route "%s" maps the value "%s" to the #[Path] %s "%s" of the page "%s", but its type is not an entity class.', $name, $value, $kind, $prop, $page));
                }
                continue;
            }
            if (!\array_key_exists($value, $paths)) {
                throw new \InvalidArgumentException(\sprintf('The route "%s" has the value "%s", but the page "%s" has no #[Path] %s "%s". Add one, or remove the value from the route.', $name, $value, $page, $kind, $value));
            }
        }
    }

    private function addRoute(RouteCollection $routes, RouteAttribute $attr, string $page, int $index): void
    {
        if ($attr->envs && !\in_array($this->env, $attr->envs, true)) {
            return;
        }
        $name = $attr->name ?? self::routeName($page).($index > 0 ? '_'.$index : '');
        $defaults = array_replace($attr->defaults, ['_controller' => 'rephp.controller.page', '_rephp_page' => $page]);
        $paths = \is_array($attr->path) ? $attr->path : [0 => (string) $attr->path];

        foreach ($paths as $locale => $path) {
            $route = new Route($path, $defaults, $attr->requirements, $attr->options, $attr->host ?? '', $attr->schemes, $attr->methods, $attr->condition ?? '');
            $this->checkValues($route, $name, $page);
            if (0 === $locale) {
                $routes->add($name, $route, $attr->priority ?? 0);
                continue;
            }
            $route->setDefault('_locale', $locale);
            $route->setRequirement('_locale', preg_quote((string) $locale));
            $route->setDefault('_canonical_route', $name);
            $routes->add($name.'.'.$locale, $route, $attr->priority ?? 0);
        }

        foreach ($attr->aliases as $alias) {
            $aliasName = $alias instanceof DeprecatedAlias ? $alias->aliasName : $alias;
            foreach (array_keys($paths) as $locale) {
                $suffix = 0 !== $locale ? '.'.$locale : '';
                $added = $routes->addAlias($aliasName.$suffix, $name.$suffix);
                if ($alias instanceof DeprecatedAlias) {
                    $added->setDeprecated($alias->package, $alias->version, $alias->message);
                }
            }
        }
    }
}
