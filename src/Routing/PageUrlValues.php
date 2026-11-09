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

use Phem\Handler\ObjectIdentifier;

/**
 * The route values of a page URL, from the values of its props. For the route
 * `/products/{id:product}`, `['product' => $product]` gives `['id' => 42]`:
 *
 * - the key of a prop with a route value becomes the name of the route value;
 * - a mapped object (`{id:product}`, `{slug:post.slug}`) gives the value of its field: an
 *   identifier of the ObjectIdentifier (Doctrine), a public property, or a getter. A prop with
 *   two route values (`/{year:post.year}/{slug:post}`) gives the two;
 * - an object of a route value with no mapping (`{product}`) gives its identifier;
 * - a backed enum gives its value, also in the query string.
 *
 * A route value name passes as it is (`['id' => 42]`). The other values go to the query string.
 * PageUrlKeys gives the keys of the route values of each page.
 */
final class PageUrlValues
{
    public function __construct(
        private readonly PageUrlKeys $keys,
        private readonly ?ObjectIdentifier $identifier = null,
    ) {
    }

    /**
     * @param string               $name   the route name of the page (its FQCN alias)
     * @param array<string, mixed> $values the values by prop or by route value
     *
     * @return array<string, mixed> the values by route value
     */
    public function of(string $name, array $values): array
    {
        // A link with no values needs no keys.
        if ([] === $values) {
            return $values;
        }

        $keys = $this->keys->of($name);

        $out = [];
        foreach ($values as $key => $value) {
            $routeValues = $keys[$key] ?? null;
            if (null === $routeValues) {
                $out[$key] = $value instanceof \BackedEnum ? $value->value : $value;
                continue;
            }

            if (!\is_object($value)) {
                if (1 !== \count($routeValues)) {
                    throw new \InvalidArgumentException(\sprintf('The value "%s" of the route "%s" gives the route values "%s": give an object, or each route value.', $key, $name, implode('", "', array_column($routeValues, 0))));
                }

                $out[$routeValues[0][0]] = $value;
                continue;
            }

            foreach ($routeValues as [$routeValue, $field]) {
                $out[$routeValue] = $this->scalar($value, $field, $name, (string) $key);
            }
        }

        return $out;
    }

    private function scalar(object $value, ?string $field, string $name, string $key): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        $ids = $this->identifier?->identify($value);

        if (null === $field) {
            return null !== $ids && 1 === \count($ids) ? reset($ids) : $value;
        }

        if (null !== $ids && \array_key_exists($field, $ids)) {
            return $ids[$field];
        }

        if (\array_key_exists($field, get_object_vars($value))) {
            return $value->{$field};
        }

        $getter = 'get'.ucfirst($field);
        if (\is_callable([$value, $getter])) {
            return $value->{$getter}();
        }

        throw new \InvalidArgumentException(\sprintf('The value "%s" of the route "%s" is a "%s" with no field "%s": give an object with a public property or a getter "%s()", or the value of the field.', $key, $name, $value::class, $field, $getter));
    }
}
