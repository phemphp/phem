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
 * A page that gets the Turbo Streams of other requests (see Phem\Stream\Streams): when it
 * renders, the browser opens one connection to the Mercure hub for the topics of the page.
 *
 *     #[Page('/guestbook'), Subscribe]
 *     final class GuestbookPage { ... }
 *
 *     // in a handler of any page, or in a controller
 *     $this->streams->to(GuestbookPage::class)->refresh('entries');
 *
 * The topics of a page are the page, the page with its #[Path] route values, and the topic of
 * Streams::everywhere(). Only a page can subscribe: each page opens its own connection.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Subscribe
{
}
