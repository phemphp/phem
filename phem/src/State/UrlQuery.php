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
            if (\array_key_exists($key, $query) && self::convert($property->getType(), $query[$key], $value)) {
                $property->setValue($component, $value);
            }
        }
    }

    /**
     * The query of the #[Url] properties of the last pass: the value of each key, or null to
     * remove the key (the value is the `except` value, or the default value of the property),
     * and the keys that add an entry to the browser history when they change.
     *
     * @return array{values: array<string, string|list<string>|null>, push: list<string>}
     *
     * @throws \LogicException when two components of the page have the same query key
     */
    public function of(Tree $tree): array
    {
        $values = [];
        $push = [];
        $owners = [];
        foreach ($tree->mounted() as $fiber) {
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
                $values[$key] = self::removesKey($property, $url, $value, $key) ? null : $value;
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
     * A list is `key[]=a&key[]=b`.
     *
     * @param array<string, string|list<string>|null> $values key => value, or null to remove the key
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
     * The text of a value in the query string.
     *
     * @return string|list<string>
     *
     * @throws \LogicException when the query string cannot keep the value
     */
    public static function encode(mixed $value, string $key): string|array
    {
        if (\is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $v): string => self::scalar($v, $key), $value);
        }

        return self::scalar($value, $key);
    }

    /**
     * Whether a value removes the key: it is the `except` value of the property, or its default
     * value with no `except`. A property with neither always has a key.
     *
     * @param string|list<string> $value
     */
    private static function removesKey(\ReflectionProperty $property, Url $url, string|array $value, string $key): bool
    {
        return match (true) {
            Url::DEFAULT !== $url->except => self::encode($url->except, $key) === $value,
            $property->hasDefaultValue() => self::encode($property->getDefaultValue(), $key) === $value,
            default => false,
        };
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
            default => throw new \LogicException(\sprintf('The query string cannot keep a value of type "%s" (the #[Url] key "%s"): use a string, an int, a float, a bool, a backed enum, or a list of them.', get_debug_type($value), $key)),
        };
    }

    /**
     * @param string|list<string>|null $value
     *
     * @return list<string>
     */
    private static function pairs(string $name, string|array|null $value): array
    {
        // As encodeURIComponent() in the browser.
        $encode = static fn (string $s): string => strtr(rawurlencode($s), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);

        return match (true) {
            null === $value => [],
            \is_array($value) => array_map(static fn (string $v): string => $encode($name).'[]='.$encode($v), $value),
            default => [$encode($name).'='.$encode($value)],
        };
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
