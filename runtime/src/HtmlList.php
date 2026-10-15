<?php

declare(strict_types=1);

namespace Rephp;

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

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /** True for a non-empty list of Html values. */
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
