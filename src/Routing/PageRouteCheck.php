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
 * Checks the route values of a page route: each route value (`{id}`, or a `defaults` key) needs
 * the #[Path] property with the same name, or the parameter with the same name of a function
 * page. The keys that start with `_` are not route values. As for the Doctrine controller
 * arguments, `{slug:post}` maps the value `slug` to the entity prop `post`, so `post` needs an
 * entity type.
 *
 * PageLoader checks a `#[Page]` route before the imports: the values of an import (a
 * `/{tenant}` prefix, a host, the defaults) are not values of the page. PageRoutes checks a route
 * of a route file.
 */
final class PageRouteCheck
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    /**
     * @throws \InvalidArgumentException when a route value has no #[Path] property or parameter
     */
    public function check(Route $route, string $name, string $page): void
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
