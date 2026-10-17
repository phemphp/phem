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

/**
 * A list of markup (like an array of JSX elements). It prints as one block, and it is iterable.
 *
 * @implements \IteratorAggregate<int, Html>
 */
final class HtmlList extends Html implements \IteratorAggregate
{
    /** @param list<Html> $items */
    public function __construct(private readonly array $items)
    {
        parent::__construct(implode('', array_map(strval(...), $items)));
    }

    /** @return \ArrayIterator<int, Html> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * True for a non-empty list of Html values.
     *
     * @phpstan-assert-if-true list<Html> $value
     */
    public static function accepts(mixed $value): bool
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$item instanceof Html) {
                return false;
            }
        }

        return true;
    }
}
