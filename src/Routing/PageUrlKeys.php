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

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * The keys that give the route values of each page, for PageUrlValues: the props and the names
 * of the route values, with the route value and the field of each one. For the route
 * `/products/{id:product}`, the key `product` gives the route value `id` from the field `id`.
 *
 * The map comes from the route collection, which is slow to load: a file of the cache directory
 * keeps it, with the resources of the routes (in debug mode, a change of a route builds it again).
 * The cache warmup writes it into the directory that it warms. Without a cache directory (the
 * tests), the map is built in memory.
 */
final class PageUrlKeys implements CacheWarmerInterface
{
    /** @var array<string, array<string, list<array{string, ?string}>>>|null route name => key => list of [route value, field] */
    private ?array $keys = null;

    private const FILE = '/phem/page_url_keys.php';

    public function __construct(
        private readonly RouterInterface $router,
        private readonly ?string $cacheDir = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * @param string $name the name of a page route, or its alias (the FQCN of the page)
     *
     * @return array<string, list<array{string, ?string}>> key => list of [route value, field]
     */
    public function of(string $name): array
    {
        $this->keys ??= $this->load();

        return $this->keys[$name] ?? [];
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (null !== $this->cacheDir) {
            $this->write(new ConfigCache($cacheDir.self::FILE, $this->debug));
        }

        return [];
    }

    /** @return array<string, array<string, list<array{string, ?string}>>> */
    private function load(): array
    {
        if (null === $this->cacheDir) {
            return self::build($this->router->getRouteCollection());
        }

        $cache = new ConfigCache($this->cacheDir.self::FILE, $this->debug);
        if (!$cache->isFresh()) {
            $this->write($cache);
        }

        return require $cache->getPath();
    }

    private function write(ConfigCache $cache): void
    {
        $routes = $this->router->getRouteCollection();

        $cache->write('<?php return '.var_export(self::build($routes), true).';'.\PHP_EOL, $routes->getResources());
    }

    /** @return array<string, array<string, list<array{string, ?string}>>> */
    private static function build(RouteCollection $routes): array
    {
        $keys = [];
        foreach ($routes->all() as $name => $route) {
            if (\is_string($route->getDefault('_phem_page'))) {
                $keys[$name] = self::keys($route);
            }
        }

        $localized = [];
        foreach (array_keys($routes->getAliases()) as $alias) {
            $route = $routes->get($alias);
            if (null === $route || !\is_string($route->getDefault('_phem_page'))) {
                continue;
            }

            $keys[$alias] = self::keys($route);

            // The aliases of a localized page have the locale (`App\Ui\Page.en`). The page name
            // alone gets the keys of one of them: the route values are the same in each locale.
            $locale = $route->getDefault('_locale');
            if (\is_string($locale) && str_ends_with($alias, '.'.$locale)) {
                $localized[substr($alias, 0, -\strlen($locale) - 1)] ??= $keys[$alias];
            }
        }

        return $keys + $localized;
    }

    /** @return array<string, list<array{string, ?string}>> */
    private static function keys(Route $route): array
    {
        $mapping = (array) ($route->getDefault('_route_mapping') ?? []);

        $keys = [];
        $names = [];
        foreach (PageRouteValues::props($route) as $value => $prop) {
            $field = match (true) {
                !isset($mapping[$value]) => null,
                \is_array($mapping[$value]) => $mapping[$value][1] ?? $value,
                default => $value,
            };

            $keys[$prop][] = [$value, $field];
            $names[$value] = [[$value, $field]];
        }

        // A prop goes first when a route value has its name.
        return $keys + $names;
    }
}
