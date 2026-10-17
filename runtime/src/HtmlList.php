<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu;

use Mamu\Dom\Attributes;

/**
 * A list of markup (like an array of JSX elements). It prints as one block, and it is iterable.
 *
 * @implements \IteratorAggregate<int, Html>
 */
final class HtmlList extends LazyHtml implements \IteratorAggregate
{
    /** @param list<Html> $items */
    public function __construct(private readonly array $items)
    {
        parent::__construct(static fn (): string => implode('', array_map(strval(...), $items)));
    }

    /** @return \ArrayIterator<int, Html> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * The list of a value with markup, or null for another value: a list with one Html item at
     * least. As in React, a null or boolean item renders nothing, and a string or a number is text.
     */
    public static function of(mixed $value): ?self
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

        return new self($items);
    }
}
