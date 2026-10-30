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

use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Tree;

/**
 * Sets the values of the bound inputs (`<input phem:bind={{ query }} />`) on the #[State]
 * properties, after the first render of an event and before its handler.
 *
 * The browser sends the values that the user changed with the next event: `"<fiber>.<property>"
 * => value`. They are not signed, so the browser can send any value. Two rules keep this safe:
 *
 *  - only a property (or a path in it) that the first render binds gets a value (see
 *    Tree::addBind()), so the browser cannot set another #[State] property or add a key;
 *  - the value must fit the type of the property: a numeric string for an `int`, a case of a
 *    backed enum, a list of strings for an `array`. A value that does not fit is ignored, and
 *    the property keeps its value.
 *
 * A path (`phem:bind={{ filters.status }}`) sets a key that the array already has. A value in an
 * array has no declared type, so the type of its current value is the type (see convertLike()).
 *
 * The first render uses the old state, as the page in the browser does: the handler ids match,
 * and the second render shows the new values as a change.
 */
final class BoundValues
{
    public function __construct(private readonly ComponentMetadata $metadata = new ComponentMetadata())
    {
    }

    /** @param array<array-key, mixed> $bound "<fiber>.<property>" => the value from the browser */
    public function apply(Tree $tree, array $bound): void
    {
        foreach ($bound as $key => $value) {
            $entry = \is_string($key) ? $tree->bound($key) : null;
            if (null === $entry) {
                continue;
            }

            [$id, $name, $path] = $entry;
            $instance = $tree->fiberById($id)?->instance;
            if (null === $instance) {
                continue;
            }

            foreach ($this->metadata->stateProperties($instance::class) as $property) {
                if ($property->getName() !== $name) {
                    continue;
                }

                if ([] === $path) {
                    if (self::convert($property->getType(), $value, $converted)) {
                        $property->setValue($instance, $converted);
                    }
                } elseif ($property->isInitialized($instance) && self::set($property->getValue($instance), $path, $value, $changed)) {
                    $property->setValue($instance, $changed);
                }
            }
        }
    }

    /**
     * Converts a value of the browser to the type of a current value, for a value in an array:
     * a string, an int, a float, a bool, or a backed enum. A null takes any value of the browser.
     * A list takes a list of strings, converted to the type of its items when they all have one
     * type (a list of ints stays a list of ints).
     *
     * @param-out mixed $out
     */
    public static function convertLike(mixed $current, mixed $value, mixed &$out = null): bool
    {
        if (!self::isBrowserValue($value)) {
            return false;
        }

        if (null === $current) {
            $out = $value;

            return true;
        }

        if (!\is_array($current)) {
            $type = self::typeOf($current);

            return null !== $type && self::toType($type, $value, $out);
        }

        if (!\is_array($value)) {
            return false;
        }

        $types = array_unique(array_map(self::typeOf(...), $current));
        $type = 1 === \count($types) ? reset($types) : null;

        $items = [];
        foreach ($value as $item) {
            if (null !== $type && !self::toType($type, $item, $item)) {
                return false;
            }

            $items[] = $item;
        }

        $out = $items;

        return true;
    }

    /**
     * Converts a value of the browser to a type: a string, a bool, a list of strings, or null.
     *
     * @param-out mixed $out
     */
    public static function convert(?\ReflectionType $type, mixed $value, mixed &$out = null): bool
    {
        if (!self::isBrowserValue($value)) {
            return false;
        }

        if (null === $type) {
            $out = $value;

            return true;
        }

        $types = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        $names = [];
        foreach ($types as $t) {
            if ($t instanceof \ReflectionNamedType) {
                $names[] = $t->getName();
            }
        }

        if ($type->allowsNull() && !\in_array('null', $names, true)) {
            $names[] = 'null';
        }

        // An empty field is null for a nullable property that does not take a string.
        if ('' === $value && \in_array('null', $names, true) && !array_intersect($names, ['string', 'mixed'])) {
            $out = null;

            return true;
        }

        // The type of the value first (`int|string` keeps "3" a string), then a conversion.
        foreach ([true, false] as $exact) {
            foreach ($names as $name) {
                if (self::to($name, $value, $exact, $out)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sets a value of the browser at a path in an array, only at a key that the array has.
     *
     * @param list<int|string> $path
     *
     * @param-out mixed $out the changed array
     */
    private static function set(mixed $array, array $path, mixed $value, mixed &$out = null): bool
    {
        $key = array_shift($path);
        if (!\is_array($array) || null === $key || !\array_key_exists($key, $array)) {
            return false;
        }

        $ok = [] === $path ? self::convertLike($array[$key], $value, $converted) : self::set($array[$key], $path, $value, $converted);
        if (!$ok) {
            return false;
        }

        $array[$key] = $converted;
        $out = $array;

        return true;
    }

    /** The type of a value in an array, for convertLike(). */
    private static function typeOf(mixed $value): ?string
    {
        return match (true) {
            $value instanceof \BackedEnum => $value::class,
            \is_string($value) => 'string',
            \is_int($value) => 'int',
            \is_float($value) => 'float',
            \is_bool($value) => 'bool',
            default => null,
        };
    }

    /**
     * The value as a type: the type of the value first, then a conversion.
     *
     * @param-out mixed $out
     */
    private static function toType(string $type, mixed $value, mixed &$out): bool
    {
        return self::to($type, $value, true, $out) || self::to($type, $value, false, $out);
    }

    /** @param-out mixed $out */
    private static function to(string $type, mixed $value, bool $exact, mixed &$out): bool
    {
        $ok = match ($type) {
            'mixed' => true,
            'null' => null === $value,
            'string' => \is_string($value) || (!$exact && (\is_int($value) || \is_float($value))),
            'bool' => \is_bool($value),
            'true' => true === $value,
            'false' => false === $value,
            'int' => \is_int($value) || (!$exact && \is_string($value) && 1 === preg_match('/\A-?\d{1,18}\z/', $value)),
            'float' => \is_float($value) || (!$exact && (\is_int($value) || (\is_string($value) && 1 === preg_match('/\A-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\z/', $value)))),
            'array', 'iterable' => \is_array($value),
            default => false,
        };

        if ($ok) {
            $out = match ($type) {
                'string' => \is_scalar($value) ? (string) $value : $value,
                'int' => \is_string($value) ? (int) $value : $value,
                'float' => \is_string($value) || \is_int($value) ? (float) $value : $value,
                default => $value,
            };

            return true;
        }

        if ($exact || !is_subclass_of($type, \BackedEnum::class) || (!\is_string($value) && !\is_int($value))) {
            return false;
        }

        $backing = (new \ReflectionEnum($type))->getBackingType()?->getName();
        if ('int' === $backing) {
            if (\is_string($value) && 1 !== preg_match('/\A-?\d{1,18}\z/', $value)) {
                return false;
            }

            $value = (int) $value;
        } else {
            $value = (string) $value;
        }

        $out = $type::tryFrom($value);

        return null !== $out;
    }

    /** A value that a form field gives: a scalar, null, or a list of strings (`<select multiple>`, checkboxes). */
    private static function isBrowserValue(mixed $value): bool
    {
        if (\is_array($value)) {
            return array_is_list($value) && array_all($value, static fn (mixed $v): bool => \is_string($v));
        }

        return null === $value || \is_scalar($value);
    }
}
