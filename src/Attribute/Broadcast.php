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

namespace Phem\Attribute;

/**
 * A page or a component that gets the Turbo Streams of other requests (see
 * Phem\Stream\Streams): when the page renders, the browser opens one connection to the Mercure
 * hub for the topics of the page and of its components.
 *
 *     #[Page('/guestbook'), Broadcast]
 *     final class GuestbookPage { ... }
 *
 *     // in a handler of any page, or in a controller
 *     $this->streams->toPage(GuestbookPage::class)->refresh('entries');
 *
 * A function page can have #[Broadcast] too. A function has no `::class`, so the streams name it
 * by its first-class callable:
 *
 *     #[Page('/guestbook'), Broadcast]
 *     function GuestbookPage(EntryRepository $entries) { ... }
 *
 *     $this->streams->toPage(GuestbookPage(...))->refresh('entries');
 *
 * A component with #[Broadcast] gets the streams on all the pages that render it, for example a
 * part of the layout. Streams::toComponent() names it:
 *
 *     #[Component, Broadcast]
 *     final class Inbox { ... }
 *
 *     $this->streams->toComponent(Inbox::class)->refresh('inbox');
 *
 * After each event handler of a class component with #[Broadcast] that does not throw, the other
 * browsers render the component again (see Phem\Handler\BroadcastMiddleware): the handler needs
 * no stream. With #[Broadcast] on some handler methods, only these handlers do it, and the
 * class needs no #[Broadcast]:
 *
 *     #[Component]
 *     final class LikeButton
 *     {
 *         #[Broadcast]
 *         public function like(): void { ... }  // the other browsers render the button again
 *
 *         public function toggle(): void { ... } // a state of this browser only
 *     }
 *
 * A closure of render() that calls such a handler needs its own #[Broadcast]
 * (`#[Broadcast] fn () => $this->like($id)`): Phem sees the closure, not the calls in it.
 *
 * With `handlers: false` on the class, the component gets the streams, and its handlers do not
 * refresh it in the other browsers: a stream of another request renders it again in each
 * browser, and a handler renders it again only in the browser of the event (as each event
 * does). Then no method and no closure of the class can have #[Broadcast]. Only a class
 * component can have `handlers: false`:
 *
 *     #[Component, Broadcast(handlers: false)]
 *     final class BagContents
 *     {
 *         public function empty(): void { ... } // the bag of this browser only
 *     }
 *
 *     // in a handler of the staff
 *     $this->streams->toComponent(BagContents::class)->refresh();
 *
 * The refresh goes to all the instances of the component. The props with #[BroadcastKey]
 * identify an instance: the refresh goes only to the instances with the same values.
 *
 * With `on`, a Doctrine listener refreshes the page or the component after each flush that
 * inserts, updates or deletes an entity of these classes (see Phem\Stream\EntityRefreshes):
 *
 *     // The URL of the order of the #[Path], also for a change of one of its lines.
 *     #[Page('/orders/{id:order}'), Broadcast(on: [Order::class, OrderLine::class])]
 *     final class OrderPage { #[Path] public Order $order; ... }
 *
 *     // All the instances, for a new or a deleted Order, a change of its status, or any change
 *     // of an OrderLine.
 *     #[Component, Broadcast(on: [Order::class => ['status'], OrderLine::class])]
 *     final class StaffAlerts { ... }
 *
 * The entity selects the URLs of a page by its #[Path] props, and the instances of a component
 * by its #[BroadcastKey] props: a prop of its class, or of the class of one of its to-one
 * associations (`$line->order`). With no such prop, all the URLs or all the instances refresh.
 * A field list refreshes after an update of these fields (or associations, or collections)
 * only: an insert and a delete always refresh. Only a page or a component has `on`, not a handler.
 *
 * The topics of a page are the page, the page with its route values, the topic of
 * Streams::everywhere(), and the topic of each component with #[Broadcast] of its first render.
 * Each page opens one connection, for all its topics.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final class Broadcast
{
    /**
     * @param string|array<array-key, mixed> $on       the entities that refresh it: a class, a list of classes, or a map of a
     *                                                 class to the fields that refresh it (`[Order::class => ['status']]`)
     * @param bool                           $handlers false: the handlers of the class component do not refresh it in the
     *                                                 other browsers, only the streams of other requests do
     */
    public function __construct(
        public readonly string|array $on = [],
        public readonly bool $handlers = true,
    ) {
    }

    /**
     * The entities of `on`, with the fields that refresh after an update (none: all the fields).
     * The classes are not checked (see Phem\Metadata\ComponentMetadata::entityBroadcasts()).
     *
     * @return array<string, list<string>>
     *
     * @throws \LogicException when `on` is not a class, a list of classes, or a map of a class to its fields
     */
    public function entities(): array
    {
        $entities = [];
        foreach ((array) $this->on as $key => $value) {
            [$class, $fields] = \is_int($key) ? [$value, []] : [$key, $value];
            if (!\is_string($class) || '' === $class || !\is_array($fields) || !array_is_list($fields)) {
                throw self::invalid(\is_int($key) ? get_debug_type($value) : $key);
            }

            $names = [];
            foreach ($fields as $field) {
                $names[] = \is_string($field) && '' !== $field ? $field : throw self::invalid($class);
            }

            $entities[ltrim($class, '\\')] = $names;
        }

        return $entities;
    }

    private static function invalid(string $what): \LogicException
    {
        return new \LogicException(\sprintf('The "on" argument of #[Broadcast] is a class, a list of classes, or a class with a list of its fields (`[Order::class => [\'status\']]`): "%s" is not one.', $what));
    }
}
