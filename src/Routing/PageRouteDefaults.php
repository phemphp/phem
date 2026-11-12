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

use Phem\Metadata\ComponentMetadata;
use Symfony\Component\Routing\Route;

/**
 * The defaults of a page route, with the default values of the page: as for the parameters of a
 * controller, `{page}` and `#[Path] public int $page = 1` make `/blog` match with the value 1.
 *
 * - A mapped value (`{slug:post}`) gets the default of its prop.
 * - A default of the route comes first.
 * - Only a scalar, null or a backed enum (its value) can be a route default.
 * - Only the last route values of a path can be optional. A default value of another route value
 *   is not a route default, so a URL without this value is still an error
 *   (`/items/{id}/edit` and `int $id = 0` do not generate `/items/0/edit`).
 */
final class PageRouteDefaults
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    /**
     * @param array<string, mixed>      $defaults     the defaults of the route attribute
     * @param array<int|string, string> $paths        the path, or the localized paths
     * @param array<string, mixed>      $requirements the requirements of the route attribute
     *
     * @return array<string, mixed>
     */
    public function of(array $defaults, array $paths, array $requirements, string $page): array
    {
        $values = $this->values($defaults, $paths, $page);

        // A value that is optional in one path but not in another is not a default. Its removal
        // can make the values before it required, so check again until nothing changes.
        do {
            $count = \count($values);
            foreach ($paths as $path) {
                $route = new Route($path, $defaults + $values, $requirements);
                $optional = self::optional($route);

                foreach ($route->compile()->getPathVariables() as $value) {
                    if (\array_key_exists($value, $values) && !\in_array($value, $optional, true)) {
                        unset($values[$value]);
                    }
                }
            }
        } while (\count($values) !== $count);

        return $defaults + $values;
    }

    /**
     * The default values of the page, by route value, for the route values that have no default.
     *
     * @param array<string, mixed>      $defaults
     * @param array<int|string, string> $paths
     *
     * @return array<string, mixed>
     */
    private function values(array $defaults, array $paths, string $page): array
    {
        $values = [];
        foreach ($this->metadata->pathDefaults($page) as $prop => $value) {
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif (null !== $value && !\is_scalar($value)) {
                continue;
            }

            // The route value of the prop: `{page}`, `{slug:post}` or `{name:post.title}`.
            foreach ($paths as $path) {
                if (1 === preg_match(\sprintf('/\{(?|([^\}:<]++):%s(?:\.[^\}<]++)?|(%1$s))(?:<.*?>)?\}/', preg_quote($prop, '/')), $path, $matches)) {
                    if (!\array_key_exists($matches[1], $defaults)) {
                        $values[$matches[1]] = $value;
                    }

                    break;
                }
            }
        }

        return $values;
    }

    /**
     * The optional route values of a path, as the RouteCompiler of Symfony finds them: the last
     * route values with a default, not `{!important}`.
     *
     * @return list<string>
     */
    private static function optional(Route $route): array
    {
        $optional = [];

        // The tokens come last first.
        foreach ($route->compile()->getTokens() as $token) {
            if ('variable' !== $token[0] || ($token[5] ?? false) || !$route->hasDefault($token[3])) {
                break;
            }

            $optional[] = $token[3];
        }

        return $optional;
    }
}
