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
 *  - only a property that the first render binds gets a value (see Tree::addBind()), so the
 *    browser cannot set another #[State] property;
 *  - the value must fit the type of the property: a numeric string for an `int`, a case of a
 *    backed enum, a list of strings for an `array`. A value that does not fit is ignored, and
 *    the property keeps its value.
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
            if (!\is_string($key) || !$tree->isBound($key)) {
                continue;
            }
            [$id, $name] = explode('.', $key, 2);
            $instance = $tree->fiberById($id)?->instance;
            if (null === $instance) {
                continue;
            }
            foreach ($this->metadata->stateProperties($instance::class) as $property) {
                if ($property->getName() === $name && self::convert($property->getType(), $value, $converted)) {
                    $property->setValue($instance, $converted);
                }
            }
        }
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
            'int' => \is_int($value) || (!$exact && \is_string($value) && 1 === preg_match('/^-?\d{1,18}$/', $value)),
            'float' => \is_float($value) || (!$exact && (\is_int($value) || (\is_string($value) && 1 === preg_match('/^-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $value)))),
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
            if (\is_string($value) && 1 !== preg_match('/^-?\d{1,18}$/', $value)) {
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
