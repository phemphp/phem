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

use Phem\Binding\EnumCase;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Tree;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

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
 * In an object (`phem:bind={{ draft.name }}`, see StateObjects), a path sets a public property
 * that can be written, and the declared type of the property is the type. A property whose type
 * is a class (a date) gets the value from the Serializer.
 *
 * The first render uses the old state, as the page in the browser does: the handler ids match,
 * and the second render shows the new values as a change.
 */
final class BoundValues
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly StateObjects $objects = new StateObjects(),
    ) {
    }

    /**
     * @param array<array-key, mixed> $bound "<fiber>.<property>" => the value from the browser
     *
     * @return array<string, array<string, bool>> the fields of each fiber id (a property, or a path: `address.city`) => whether the value fit (see Phem\State\StateValidator)
     */
    public function apply(Tree $tree, array $bound): array
    {
        $applied = [];
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

                $current = $property->isInitialized($instance) ? $property->getValue($instance) : null;
                if ([] === $path) {
                    $fits = $this->convertTo($property->getType(), $value, $converted, $current);
                    if ($fits) {
                        $property->setValue($instance, $converted);
                    }
                } else {
                    // Only a property of a class keeps objects (see StateObjects): an object in
                    // an array is not a value of the state.
                    $objects = null !== StateObjects::classOf($property->getType());
                    $fits = false;
                    if ($property->isInitialized($instance) && $this->set($current, $path, $value, $objects, $changed)) {
                        $property->setValue($instance, $changed);
                        $fits = true;
                    }
                }

                $applied[$id][implode('.', [$name, ...$path])] = $fits;
            }
        }

        return $applied;
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
     * Converts a value of the browser to a declared type, as convert(). A class that the
     * Serializer makes (a date, see StateObjects) gets the object of the value. A date is in the
     * timezone of the current date: the field shows it in that timezone (see
     * Phem\Dom\Attributes::bindValue()).
     *
     * @param mixed $current the current value of the property
     *
     * @param-out mixed $out
     */
    private function convertTo(?\ReflectionType $type, mixed $value, mixed &$out = null, mixed $current = null): bool
    {
        if (self::convert($type, $value, $out)) {
            return true;
        }

        $class = StateObjects::classOf($type);
        if (!\is_scalar($value) || null === $class) {
            return false;
        }

        // DateTimeNormalizer::TIMEZONE_KEY, with no error when the Serializer is not installed.
        $context = $current instanceof \DateTimeInterface ? ['datetime_timezone' => $current->getTimezone()] : [];

        try {
            $out = $this->objects->fromState($class, $value, $context);
        } catch (ExceptionInterface|\TypeError|\ValueError) {
            return false;
        }

        return true;
    }

    /**
     * Sets a value of the browser at a path: at a key that an array has, or at a property of an
     * object that can be written (see writable()).
     *
     * @param list<int|string> $path
     * @param bool             $objects whether the path can go into an object
     *
     * @param-out mixed $out the changed array or object
     */
    private function set(mixed $target, array $path, mixed $value, bool $objects, mixed &$out = null): bool
    {
        $key = array_shift($path);
        if (null === $key) {
            return false;
        }

        if (\is_object($target)) {
            return $objects && $this->setProperty($target, (string) $key, $path, $value, $out);
        }

        if (!\is_array($target) || !\array_key_exists($key, $target)) {
            return false;
        }

        $ok = [] === $path ? self::convertLike($target[$key], $value, $converted) : $this->set($target[$key], $path, $value, $objects, $converted);
        if (!$ok) {
            return false;
        }

        $target[$key] = $converted;
        $out = $target;

        return true;
    }

    /**
     * Sets a value of the browser at a property of an object, or at a path in it. The object
     * changes in place: after the handlers, a changed object becomes a copy (see StateCopies).
     *
     * @param list<int|string> $path
     *
     * @param-out mixed $out the changed object
     */
    private function setProperty(object $object, string $name, array $path, mixed $value, mixed &$out = null): bool
    {
        $property = self::writable($object, $name);
        if (null === $property) {
            return false;
        }

        $current = $property->isInitialized($object) ? $property->getValue($object) : null;
        $converted = null;
        if ([] === $path) {
            $ok = $this->convertTo($property->getType(), $value, $converted, $current);
        } else {
            $ok = $property->isInitialized($object) && $this->set($current, $path, $value, true, $converted);
        }

        if (!$ok) {
            return false;
        }

        $property->setValue($object, $converted);
        $out = $object;

        return true;
    }

    /** A property of an object that a bound value can set: public, not static, and not readonly. */
    private static function writable(object $object, string $name): ?\ReflectionProperty
    {
        if (!property_exists($object, $name)) {
            return null;
        }

        $property = new \ReflectionProperty($object, $name);
        if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly() || $property->isPrivateSet() || $property->isProtectedSet()) {
            return null;
        }

        return $property->isVirtual() && !$property->hasHook(\PropertyHookType::Set) ? null : $property;
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

        $out = EnumCase::from($type, $value);

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
