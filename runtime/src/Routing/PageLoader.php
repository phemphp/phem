<?php

declare(strict_types=1);

namespace Rephp\Routing;

use Rephp\Manifest\Manifest;
use Rephp\Metadata\ComponentMetadata;
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
 * The events of a page go to the URL of the page (`POST`, see EventController), so the access
 * rules of the page (`access_control`, the firewall, the host and the condition of the route)
 * apply to them too. Each page route has an event route (`rephp_event.<name>`) with the same
 * path and the `POST` method. It comes first, so a page route without methods does not take
 * the events.
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
    /** The prefix of the event route names: `rephp_event.app_pages_counter`. */
    public const EVENT_ROUTE = 'rephp_event';

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
        $paths = $this->metadata->pathProps($page);
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
                ->setDefault('_controller', 'rephp.controller.event')
                ->setDefault('_rephp_methods', $route->getMethods());
            $routes->add(self::EVENT_ROUTE.'.'.$routeName, $event, $attr->priority ?? 0);
            $routes->add($routeName, $route, $attr->priority ?? 0);
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
