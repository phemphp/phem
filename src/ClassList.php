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
 * A mergeable `class` value: `classes('px-4 py-2')` in markup, `Phem\classes(...)` in PHP.
 *
 * When one of two values of `class` (or `className`) is a ClassList, the result is a ClassList
 * with the earlier classes and then the later classes (see merge()). So a component keeps its
 * default classes, and the classes of the caller merge into them:
 *
 *     <button className={{ classes('px-4 py-2 bg-blue-500') }} {{ ...rest }} />
 *
 * The classes merge only when the attribute renders: Phem\Dom\ClassAttribute gives them to the
 * ClassMerger of the application, so the later class wins a conflict (`px-2` and then `px-4`
 * gives `px-4` with the Tailwind merger).
 */
final readonly class ClassList
{
    /**
     * @param list<string> $classes the classes in order, with the duplicates
     */
    private function __construct(public array $classes)
    {
    }

    /**
     * The inputs are as in clsx: strings (split on white space), lists, nested lists, maps of
     * conditions (`['active' => $isActive]` keeps `active` when the value is true), other
     * ClassList values, and null or false (no class).
     */
    public static function of(mixed ...$inputs): self
    {
        $classes = [];
        self::collect($inputs, $classes);

        return new self($classes);
    }

    /**
     * Two values of the same `class` attribute or prop: a ClassList with the classes of both
     * when one of them is a ClassList. Else the later value, as in React.
     */
    public static function merge(mixed $earlier, mixed $later): mixed
    {
        if (!$earlier instanceof self && !$later instanceof self) {
            return $later;
        }

        return self::of($earlier, $later);
    }

    /**
     * @param list<string> $classes
     */
    private static function collect(mixed $input, array &$classes): void
    {
        if (null === $input || \is_bool($input) || '' === $input) {
            return;
        }

        if ($input instanceof self) {
            array_push($classes, ...$input->classes);

            return;
        }

        if (\is_array($input)) {
            foreach ($input as $key => $value) {
                if (\is_string($key)) {
                    // A map of conditions: the key is the class.
                    if ($value) {
                        self::collect($key, $classes);
                    }
                } else {
                    self::collect($value, $classes);
                }
            }

            return;
        }

        if (!\is_scalar($input) && !$input instanceof \Stringable) {
            throw new \InvalidArgumentException(\sprintf('A class takes a string, a list, a map of conditions or a ClassList, "%s" given.', get_debug_type($input)));
        }

        foreach (preg_split('/\s+/', trim((string) $input), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
            $classes[] = $class;
        }
    }
}
