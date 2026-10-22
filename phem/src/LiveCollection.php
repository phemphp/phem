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
 * A list of items whose changes go to all the open pages, with no topic and no target id:
 *
 *     #[Page('/chat'), Subscribe, Provide]
 *     final class Chat
 *     {
 *         public LiveCollection $messages;
 *
 *         public function post(string $name, string $text): void
 *         {
 *             $this->messages->append(new ChatMessage($name, $text));
 *         }
 *
 *         public function render(): Node
 *         {
 *             return (
 *                 <ul>
 *                     {% for m in messages %}
 *                         <li key={{ m.id }}>{{ m.name }}: {{ m.text }}</li>
 *                     {% endfor %}
 *                 </ul>
 *             );
 *         }
 *     }
 *
 * - The page prints the live collection in one `{% for %}`, the only content of its element. phemc
 *   gives the element its id, and compiles the loop body as the markup of one item.
 * - After the event handler, each change goes to the page of the event (with its #[Path] route
 *   values) as a Turbo Stream, with the loop body rendered for the item: append() and prepend()
 *   add it to the element, replace() and remove() change the element of the item. The `key` of
 *   the item gives its id: give the same key to the same item. refresh() renders the component
 *   of the live collection again in each page, for the data around the live collection (a count).
 * - The page must have #[Subscribe]. The class of the live collection has no #[State]: its data
 *   loads again in each render.
 * - LiveCollection::of() gives the items of the server render, for example from a repository in
 *   mount(). A live collection property with no value is empty and has no history: the page
 *   keeps the pushed items when it renders again (`data-phem-ignore` on the element).
 * - The data stays in the application: a live collection only records the changes of one request.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class LiveCollection implements \IteratorAggregate, \Countable
{
    public const APPEND = 'append';
    public const PREPEND = 'prepend';
    public const REPLACE = 'replace';
    public const REMOVE = 'remove';
    public const REFRESH = 'refresh';

    /** @var list<array{0: string, 1: mixed}> [action, item] */
    private array $changes = [];

    /** @param list<mixed> $items */
    private function __construct(
        private array $items,
        private readonly bool $history,
    ) {
    }

    /**
     * A live collection with the items of the server render (its history).
     *
     * @param iterable<mixed> $items
     */
    public static function of(iterable $items): self
    {
        return new self(array_values(\is_array($items) ? $items : iterator_to_array($items, false)), true);
    }

    /**
     * A live collection with no history: the pushes are its only items.
     *
     * @internal Phem sets it for a live collection property with no value
     */
    public static function withoutHistory(): self
    {
        return new self([], false);
    }

    /** Adds the item at the end of the element of the live collection, in all the open pages. */
    public function append(mixed $item): void
    {
        $this->items[] = $item;
        $this->changes[] = [self::APPEND, $item];
    }

    /** Adds the item at the start of the element of the live collection, in all the open pages. */
    public function prepend(mixed $item): void
    {
        array_unshift($this->items, $item);
        $this->changes[] = [self::PREPEND, $item];
    }

    /** Replaces the element of the item (the element with its key), in all the open pages. */
    public function replace(mixed $item): void
    {
        $this->changes[] = [self::REPLACE, $item];
    }

    /** Removes the element of the item (the element with its key), in all the open pages. */
    public function remove(mixed $item): void
    {
        $this->items = array_values(array_filter($this->items, static fn (mixed $i): bool => $i !== $item));
        $this->changes[] = [self::REMOVE, $item];
    }

    /**
     * Renders the component of the live collection again in all the open pages, with a normal
     * event and with its subtree: mount() reads the new data, and a #[State] keeps the value of
     * the browser. Use it for the data around the live collection that its items change (a count).
     */
    public function refresh(): void
    {
        $this->changes[] = [self::REFRESH, null];
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /** @internal see the `phem_live_collection()` Twig function */
    public function hasHistory(): bool
    {
        return $this->history;
    }

    /**
     * The changes since the last call, in their order.
     *
     * @internal see Phem\Stream\LiveStreams
     *
     * @return list<array{0: string, 1: mixed}> [action, item]
     */
    public function drain(): array
    {
        $changes = $this->changes;
        $this->changes = [];

        return $changes;
    }
}
