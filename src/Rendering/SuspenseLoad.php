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

namespace Phem\Rendering;

/**
 * How a render loads the `<Suspense>` boundaries that show their fallback (see Phem\Suspense).
 * A `lazy` boundary always loads with an event.
 *
 * @internal
 */
enum SuspenseLoad
{
    /** The browser loads them with a load event: the events, a Turbo visit, and a page with no stream. */
    case Event;
    /** The response of the page streams them after the page (see SuspenseStream). */
    case Stream;
    /** The children render in the page, with no fallback: a mounted component in a test. */
    case Inline;

    /** Whether a boundary that shows its fallback loads in this render. */
    public function loadsNow(): bool
    {
        return self::Inline === $this;
    }

    /** Whether the render puts a boundary that shows its fallback in the queue of the stream (see Tree::defer()). */
    public function defers(): bool
    {
        return self::Stream === $this;
    }

    /**
     * Whether an exception of the children goes on when the boundary has no error slot: the event
     * fails. Else the fallback stays, and the browser loads the boundary with an event.
     */
    public function throws(): bool
    {
        return self::Event === $this;
    }
}
