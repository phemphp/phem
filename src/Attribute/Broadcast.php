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
 *     function Guestbook(EntryRepository $entries) { ... }
 *
 *     $this->streams->toPage(Guestbook(...))->refresh('entries');
 *
 * A component with #[Broadcast] gets the streams on all the pages that render it, for example a
 * part of the layout. Streams::toComponent() names it, with no route values:
 *
 *     #[Component, Broadcast]
 *     final class Inbox { ... }
 *
 *     $this->streams->toComponent(Inbox::class)->refresh('inbox');
 *
 * The topics of a page are the page, the page with its route values, the topic of
 * Streams::everywhere(), and the topic of each component with #[Broadcast] of its first render.
 * Each page opens one connection, for all its topics.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION)]
final class Broadcast
{
}
