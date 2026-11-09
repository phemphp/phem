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
 * A list whose items live in the browser only: a chat, a feed, a log or an infinite scroll. The
 * items are not in the state, and a later event does not load them again.
 *
 * A class component has a public `Feed` property with no attribute, and the markup shows it with
 * `phem:feed`. Phem sets a new feed on each new instance. `mount()` adds the first items, and a
 * handler adds, changes or removes items: the response has a Turbo Stream for each change, and the
 * browser keeps the other items.
 *
 *     #[Component]
 *     final class ChatRoom
 *     {
 *         public Feed $messages;
 *
 *         public function mount(): void
 *         {
 *             $this->messages->append(...$this->chat->latest(50));
 *         }
 *
 *         public function remove(Message $message): void
 *         {
 *             $this->chat->remove($message);
 *             $this->messages->delete($message);
 *         }
 *     }
 *
 * An item is an entity: Phem identifies it with its class and its identifier (see
 * Phem\Handler\ObjectIdentifier), and loads it again for an event from inside the item.
 */
final class Feed
{
    /** @var list<array{0: 'append'|'prepend'|'update'|'delete'|'reset', 1: list<object>}> the changes of this request, in their order */
    private array $operations = [];

    /** @var list<object> the items that the browser sent back with the event (see Phem\Rendering\FeedBinder) */
    private array $loaded = [];

    /**
     * @internal Phem makes the feed of a component (see Phem\Rendering\FeedBinder)
     *
     * @param string $name the name of the property
     */
    public function __construct(public readonly string $name)
    {
    }

    /** Adds items at the end of the list, in their order. An item that is in the list already moves to the end. */
    public function append(object ...$items): void
    {
        $this->add('append', $items);
    }

    /** Adds items at the start of the list, in their order. An item that is in the list already moves to the start. */
    public function prepend(object ...$items): void
    {
        $this->add('prepend', $items);
    }

    /** Renders an item of the list again, in its place. */
    public function update(object $item): void
    {
        $this->add('update', [$item]);
    }

    /** Removes an item from the list. */
    public function delete(object $item): void
    {
        $this->add('delete', [$item]);
    }

    /** Replaces all the items of the list. */
    public function reset(object ...$items): void
    {
        $this->operations[] = ['reset', array_values($items)];
    }

    /**
     * @internal an item that the browser sent back with the event: it renders, so the handlers of the item are found
     */
    public function load(object $item): void
    {
        $this->loaded[] = $item;
    }

    /**
     * @internal
     *
     * @return list<array{0: 'append'|'prepend'|'update'|'delete'|'reset', 1: list<object>}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @internal
     *
     * @return list<object>
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /**
     * @internal the items that render in this request: each item one time, with no deleted item
     *
     * @return list<object>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->loaded as $item) {
            $items[spl_object_id($item)] = $item;
        }

        foreach ($this->operations as [$action, $list]) {
            if ('reset' === $action) {
                $items = [];
            }

            foreach ($list as $item) {
                if ('delete' === $action) {
                    unset($items[spl_object_id($item)]);
                } else {
                    $items[spl_object_id($item)] = $item;
                }
            }
        }

        return array_values($items);
    }

    /**
     * @internal the items of a new list in the browser, in their order: the list starts empty, and the changes go in their order
     *
     * @return list<object>
     */
    public function ordered(): array
    {
        $list = [];
        foreach ($this->operations as [$action, $items]) {
            $ids = array_map(spl_object_id(...), $items);
            $keyed = array_combine($ids, $items);

            $list = match ($action) {
                'append' => array_diff_key($list, $keyed) + $keyed,
                'prepend' => $keyed + array_diff_key($list, $keyed),
                'reset' => $keyed,
                'delete' => array_diff_key($list, $keyed),
                // The item stays in its place: the browser has no other place for it.
                'update' => $list,
            };
        }

        return array_values($list);
    }

    /**
     * @param 'append'|'prepend'|'update'|'delete' $action
     * @param array<object>                        $items
     */
    private function add(string $action, array $items): void
    {
        if ([] === $items) {
            return;
        }

        $this->operations[] = [$action, array_values($items)];
    }
}
