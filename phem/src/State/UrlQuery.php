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

namespace Phem\State;

use Phem\Attribute\Url;
use Phem\Binding\ComponentName;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Tree;

/**
 * The #[Url] properties in the query string of the page URL (see Phem\Attribute\Url).
 *
 * The first render of a page reads them from the query string (read()). Each render gives the
 * query of the page (of()): the browser writes it into the URL. Only the first render reads the
 * URL: in an event, the signed state has the values, and the URL only shows them.
 *
 * The query string comes from the user, as a bound value (see BoundValues). A value must fit
 * the type of the property, or it is ignored and the property keeps its value.
 *
 * An array with keys (a map) is nested in the query string: `filters[status]=open`. The query
 * has only the keys with a value other than the default value (or the `except` value), and the
 * first render sets only the keys that the array has after mount(), as a `phem:bind` path does:
 * the type of the current value is the type of the key.
 *
 * @phpstan-type QueryValue string|list<string>|array<array-key, mixed>
 */
final class UrlQuery
{
    public function __construct(private readonly ComponentMetadata $metadata = new ComponentMetadata())
    {
    }

    /**
     * Sets the #[Url] properties of a new component from the query string of the page request.
     *
     * @param array<array-key, mixed> $query the query values (`$request->query->all()`)
     */
    public function read(object $component, array $query): void
    {
        if ([] === $query) {
            return;
        }
        foreach ($this->metadata->urlProperties($component::class) as $key => [$property]) {
            if (!\array_key_exists($key, $query)) {
                continue;
            }
            $current = $property->isInitialized($component) ? $property->getValue($component) : null;
            $ok = self::isMap($current) ? self::readMap($current, $query[$key], $value) : self::convert($property->getType(), $query[$key], $value);
            if ($ok) {
                $property->setValue($component, $value);
            }
        }
    }

    /**
     * The query of the #[Url] properties of the fibers that rendered in the last pass: the value
     * of each key, or null to remove the key (the value is the `except` value, or the default
     * value of the property), and the keys that add an entry to the browser history when they
     * change. The browser keeps the keys of the other fibers: their state did not change.
     *
     * @return array{values: array<string, QueryValue|null>, push: list<string>}
     *
     * @throws \LogicException when two components of the page have the same query key
     */
    public function of(Tree $tree): array
    {
        $values = [];
        $push = [];
        $owners = [];
        foreach ($tree->rendered() as $fiber) {
            $instance = $fiber->instance;
            if (null === $instance) {
                continue;
            }
            foreach ($this->metadata->urlProperties($instance::class) as $key => [$property, $url]) {
                if (isset($owners[$key])) {
                    throw new \LogicException(\sprintf('Two components of the page keep the query key "%s" (#[Url]): <%s> and <%s>. Give one of them another key: #[Url(as: \'…\')].', $key, ComponentName::short($owners[$key]), ComponentName::short($instance::class)));
                }
                $owners[$key] = $instance::class;
                if (!$property->isInitialized($instance)) {
                    continue;
                }
                $value = self::encode($property->getValue($instance), $key);
                $base = self::base($property, $url, $key);
                $values[$key] = null === $base ? $value : self::without($value, $base);
                if ($url->history) {
                    $push[] = $key;
                }
            }
        }

        return ['values' => $values, 'push' => $push];
    }

    /**
     * Converts a query value to a type, as BoundValues does. A bool is "1" or "0" ("true" and
     * "false" too).
     *
     * @param-out mixed $out
     */
    public static function convert(?\ReflectionType $type, mixed $value, mixed &$out = null): bool
    {
        if (\is_string($value) && self::acceptsBool($type)) {
            $bool = ['1' => true, 'true' => true, '0' => false, 'false' => false][$value] ?? null;
            if (null !== $bool) {
                $out = $bool;

                return true;
            }
        }

        return BoundValues::convert($type, $value, $out);
    }

    /**
     * Writes the query of the #[Url] properties into a URL, as the `phem` Stimulus controller
     * does: a key keeps its place, a new key goes at the end, and the other keys do not change.
     * A list is `key[]=a&key[]=b`, and a map is `key[a]=1&key[b]=2`.
     *
     * @param array<string, QueryValue|null> $values key => value, or null to remove the key
     */
    public static function merge(string $url, array $values): string
    {
        $parts = explode('#', $url, 2);
        $fragment = $parts[1] ?? null;
        [$path, $search] = explode('?', $parts[0], 2) + [1 => ''];
        $out = [];
        $done = [];
        foreach (explode('&', $search) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            $bracket = strpos($name, '[');
            $name = false !== $bracket && $bracket > 0 ? substr($name, 0, $bracket) : $name;
            if (!\array_key_exists($name, $values)) {
                $out[] = $pair;
            } elseif (!isset($done[$name])) {
                $done[$name] = true;
                array_push($out, ...self::pairs($name, $values[$name]));
            }
        }
        foreach ($values as $name => $value) {
            if (!isset($done[$name])) {
                array_push($out, ...self::pairs($name, $value));
            }
        }

        return $path.([] !== $out ? '?'.implode('&', $out) : '').(null !== $fragment ? '#'.$fragment : '');
    }

    /**
     * The text of a value in the query string: a string, a list of strings, or a map of them.
     *
     * @return QueryValue
     *
     * @throws \LogicException when the query string cannot keep the value
     */
    public static function encode(mixed $value, string $key): string|array
    {
        if (self::isMap($value)) {
            return array_map(static fn (mixed $v): string|array => self::encode($v, $key), $value);
        }
        if (\is_array($value)) {
            return array_map(static fn (mixed $v): string => self::scalar($v, $key), $value);
        }

        return self::scalar($value, $key);
    }

    /**
     * Whether a value is an array with keys (not a list), nested in the query string.
     *
     * @phpstan-assert-if-true non-empty-array<array-key, mixed> $value
     */
    public static function isMap(mixed $value): bool
    {
        return \is_array($value) && [] !== $value && !array_is_list($value);
    }

    /**
     * The value with no key in the query string: the `except` value of the property, or its
     * default value with no `except`. A property with neither has none: it always has a key.
     *
     * @return QueryValue|null
     */
    private static function base(\ReflectionProperty $property, Url $url, string $key): string|array|null
    {
        return match (true) {
            Url::DEFAULT !== $url->except => self::encode($url->except, $key),
            $property->hasDefaultValue() => self::encode($property->getDefaultValue(), $key),
            default => null,
        };
    }

    /**
     * A value without its parts that are equal to the base value: null when all of it is equal.
     * A map keeps only its keys with another value, so `?filters[status]=open` and not all the
     * keys of the filters.
     *
     * @param QueryValue $value
     * @param QueryValue $base
     *
     * @return QueryValue|null
     */
    private static function without(string|array $value, string|array $base): string|array|null
    {
        if ($value === $base) {
            return null;
        }
        if (!self::isMap($value) || !self::isMap($base)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $v = \array_key_exists($k, $base) ? self::without($v, $base[$k]) : $v;
            if (null !== $v) {
                $out[$k] = $v;
            }
        }

        return [] !== $out ? $out : null;
    }

    /**
     * Sets the values of the query string on a map, only at the keys that the map has. The type
     * of the current value is the type of the key, as for a `phem:bind` path. A value that does
     * not fit is ignored, and the key keeps its value.
     *
     * @param array<array-key, mixed> $current
     *
     * @param-out array<array-key, mixed> $out
     */
    private static function readMap(array $current, mixed $query, mixed &$out = null): bool
    {
        if (!\is_array($query)) {
            return false;
        }
        $changed = false;
        foreach ($query as $k => $v) {
            if (!\array_key_exists($k, $current)) {
                continue;
            }
            $ok = self::isMap($current[$k]) ? self::readMap($current[$k], $v, $value) : self::convertLike($current[$k], $v, $value);
            if ($ok) {
                $current[$k] = $value;
                $changed = true;
            }
        }
        $out = $current;

        return $changed;
    }

    /**
     * Converts a query value to the type of a current value, as BoundValues::convertLike(). A
     * bool is "1" or "0" ("true" and "false" too).
     *
     * @param-out mixed $out
     */
    private static function convertLike(mixed $current, mixed $value, mixed &$out = null): bool
    {
        if (\is_bool($current) && \is_string($value)) {
            $bool = ['1' => true, 'true' => true, '0' => false, 'false' => false][$value] ?? null;
            $out = $bool;

            return null !== $bool;
        }

        return BoundValues::convertLike($current, $value, $out);
    }

    private static function scalar(mixed $value, string $key): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '0',
            \is_string($value), \is_int($value) => (string) $value,
            // The shortest text that gives the same float.
            \is_float($value) && is_finite($value) => json_encode($value, \JSON_THROW_ON_ERROR),
            $value instanceof \BackedEnum => (string) $value->value,
            default => throw new \LogicException(\sprintf('The query string cannot keep a value of type "%s" (the #[Url] key "%s"): use a string, an int, a float, a bool, a backed enum, a list of them, or an array with keys of them.', get_debug_type($value), $key)),
        };
    }

    /**
     * @param QueryValue|null $value
     *
     * @return list<string>
     */
    private static function pairs(string $name, string|array|null $value): array
    {
        return self::nestedPairs(self::encodeComponent($name), $value);
    }

    /**
     * The pairs of a value under an encoded name: `name[]=v` for each item of a list, and
     * `name[k]=v` for each key of a map.
     *
     * @param QueryValue|null $value
     *
     * @return list<string>
     */
    private static function nestedPairs(string $name, mixed $value): array
    {
        if (null === $value) {
            return [];
        }
        if (self::isMap($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                array_push($out, ...self::nestedPairs($name.'['.self::encodeComponent((string) $k).']', $v));
            }

            return $out;
        }
        if (\is_array($value)) {
            return array_values(array_map(static fn (mixed $v): string => $name.'[]='.self::encodeComponent((string) $v), $value));
        }

        return [$name.'='.self::encodeComponent((string) $value)];
    }

    /** As encodeURIComponent() in the browser. */
    private static function encodeComponent(string $s): string
    {
        return strtr(rawurlencode($s), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    private static function acceptsBool(?\ReflectionType $type): bool
    {
        $types = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        return array_any($types, static fn (\ReflectionType $t): bool => $t instanceof \ReflectionNamedType && \in_array($t->getName(), ['bool', 'true', 'false'], true));
    }
}
