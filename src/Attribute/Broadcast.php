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
 * The topics of a page are the page, the page with its route values, the topic of
 * Streams::everywhere(), and the topic of each component with #[Broadcast] of its first render.
 * Each page opens one connection, for all its topics.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final class Broadcast
{
    /**
     * @param bool $handlers false: the handlers of the class component do not refresh it in the
     *                       other browsers, only the streams of other requests do
     */
    public function __construct(
        public readonly bool $handlers = true,
    ) {
    }
}
