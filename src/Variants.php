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

namespace Phem;

/**
 * The classes of the variants of a component, as in cva: the base classes, the classes of each
 * value of each axis, and the classes of a combination of values (a compound variant).
 *
 *     static $recipe = variants(
 *         base: 'px-4 py-2',
 *         variants: [
 *             'tone' => ['primary' => 'bg-blue-500 text-white', 'outline' => 'border border-blue-500'],
 *             'size' => ['sm' => 'text-sm', 'lg' => 'text-lg'],
 *         ],
 *         compound: [
 *             [['tone' => Tone::Outline, 'size' => Size::Lg], 'border-2'],
 *         ],
 *     );
 *
 *     return $recipe->apply(tone: $tone, size: $size);
 *
 * The values are enum props, so the PHP defaults of the props are the default variants. A backed
 * enum gives its value, a pure enum its name, and a bool 'true' or 'false'. The result is a
 * ClassList, so the classes of the caller merge into it (see ClassList::merge()).
 *
 * The recipe is strict, so a mistake doesn't silently lose classes: an axis or a value that isn't
 * in the recipe is an error. Give a value that has no classes an empty string.
 */
final readonly class Variants
{
    /**
     * The conditions of each compound variant, with the keys of the values, and its classes.
     *
     * @var list<array{array<string, list<string>>, mixed}>
     */
    private array $compound;

    /**
     * @param array<string, array<string|int, mixed>>  $variants the classes of each value of each axis
     * @param list<array{array<string, mixed>, mixed}> $compound the conditions and the classes of each compound variant
     */
    public function __construct(
        private mixed $base = null,
        private array $variants = [],
        array $compound = [],
    ) {
        $this->compound = array_map($this->compoundVariant(...), $compound);
    }

    /**
     * The classes in this order: the base classes, the classes of each axis in the order of the
     * recipe, and then the classes of each compound variant that matches. An axis without a value
     * (or with null) adds no class.
     */
    public function apply(mixed ...$values): ClassList
    {
        $chosen = [];
        foreach ($values as $axis => $value) {
            $axis = $this->axis($axis);
            $key = self::key($value);

            if (null !== $key) {
                $chosen[$axis] = $this->value($axis, $key);
            }
        }

        $classes = [$this->base];
        foreach ($this->variants as $axis => $options) {
            if (isset($chosen[$axis])) {
                $classes[] = $options[$chosen[$axis]];
            }
        }

        foreach ($this->compound as [$conditions, $compoundClasses]) {
            if (self::matches($conditions, $chosen)) {
                $classes[] = $compoundClasses;
            }
        }

        return ClassList::of(...$classes);
    }

    /**
     * A compound variant is a pair: the conditions and the classes. A condition is one value or
     * a list of values (one of them must match).
     *
     * @return array{array<string, list<string>>, mixed}
     */
    private function compoundVariant(mixed $compound): array
    {
        if (!\is_array($compound) || !array_is_list($compound) || 2 !== \count($compound) || !\is_array($compound[0]) || [] === $compound[0]) {
            throw new \InvalidArgumentException('A compound variant is a pair of conditions and classes: [[\'tone\' => Tone::Danger, \'size\' => Size::Lg], \'font-bold\'].');
        }

        [$conditions, $classes] = $compound;

        $keys = [];
        foreach ($conditions as $axis => $condition) {
            $axis = $this->axis($axis);
            $keys[$axis] = [];

            foreach (\is_array($condition) ? $condition : [$condition] as $value) {
                $keys[$axis][] = $this->value($axis, self::key($value) ?? throw new \InvalidArgumentException(\sprintf('A condition of the axis "%s" takes a value, null given.', $axis)));
            }
        }

        return [$keys, $classes];
    }

    /**
     * @param array<string, list<string>> $conditions
     * @param array<string, string>       $chosen
     */
    private static function matches(array $conditions, array $chosen): bool
    {
        foreach ($conditions as $axis => $keys) {
            if (!isset($chosen[$axis]) || !\in_array($chosen[$axis], $keys, true)) {
                return false;
            }
        }

        return true;
    }

    private function axis(int|string $axis): string
    {
        if (!\is_string($axis) || !\array_key_exists($axis, $this->variants)) {
            throw new \InvalidArgumentException(\sprintf('The variants have no axis "%s". The axes are "%s".', $axis, implode('", "', array_keys($this->variants))));
        }

        return $axis;
    }

    private function value(string $axis, string $key): string
    {
        if (!\array_key_exists($key, $this->variants[$axis])) {
            throw new \InvalidArgumentException(\sprintf('The axis "%s" has no value "%s". The values are "%s".', $axis, $key, implode('", "', array_keys($this->variants[$axis]))));
        }

        return $key;
    }

    /**
     * The key of a value in the classes of an axis.
     */
    private static function key(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(\sprintf('A variant takes an enum, a string, an int or a bool, "%s" given.', get_debug_type($value))),
        };
    }
}
