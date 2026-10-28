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

use Phem\Dom\Attributes;

/**
 * A list of markup (like an array of JSX elements). It prints as one block, and it is iterable.
 *
 * @implements \IteratorAggregate<int, Html>
 */
final class HtmlList extends LazyHtml implements \IteratorAggregate
{
    /**
     * @param list<Html>             $items
     * @param list<mixed>|null       $values the list that the items come from (see of()), or null for the items
     * @param (\Closure(): int)|null $pass   the render pass now, or null to render one time only (see LazyHtml)
     */
    public function __construct(private readonly array $items, private readonly ?array $values = null, ?\Closure $pass = null)
    {
        parent::__construct(static fn (): string => implode('', array_map(strval(...), $items)), $pass);
    }

    /**
     * The list as an array, with its null and boolean items. A prop with the type `array` gets
     * it (see wantsArray()).
     *
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return $this->values ?? $this->items;
    }

    /** Whether a prop of this type takes an array, but not an HtmlList (`array`, `?array`). */
    public static function wantsArray(?\ReflectionType $type): bool
    {
        $array = false;
        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $t) {
            if (!$t instanceof \ReflectionNamedType) {
                continue;
            }

            $name = $t->getName();
            if ('array' === $name) {
                $array = true;
            } elseif (\in_array($name, ['mixed', 'object', 'iterable'], true) || (!$t->isBuiltin() && is_a(self::class, $name, true))) {
                return false;
            }
        }

        return $array;
    }

    /** @return \ArrayIterator<int, Html> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * The list of a value with markup, or null for another value: a list with one Html item at
     * least. As in React, a null or boolean item renders nothing, and a string or a number is text.
     *
     * @param (\Closure(): int)|null $pass the render pass now, or null to render one time only (see LazyHtml)
     */
    public static function of(mixed $value, ?\Closure $pass = null): ?self
    {
        if (!\is_array($value) || !array_is_list($value) || !array_any($value, static fn (mixed $item): bool => $item instanceof Html)) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if ($item instanceof Html) {
                $items[] = $item;
            } elseif (\is_string($item) || \is_int($item) || \is_float($item)) {
                $items[] = new Html(Attributes::e((string) $item));
            } elseif (null !== $item && !\is_bool($item)) {
                return null;
            }
        }

        return new self($items, $value, $pass);
    }
}
