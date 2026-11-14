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

use Phem\Attribute\Prop;
use Phem\Attribute\State;

/**
 * The `<Suspense>` tag: content that loads after the page, as in React. The page renders the
 * fallback, and the response of the page streams the children after the page (or, with `lazy`,
 * the browser loads them with an event when the fallback comes into view):
 *
 *     use Phem\Suspense;
 *
 *     <Suspense lazy>
 *         <Suspense.fallback><p>Loading the report…</p></Suspense.fallback>
 *         <Suspense.error><p>The report is not available.</p></Suspense.error>
 *         <SalesReport />
 *     </Suspense>
 *
 * The children do not render before the page: a slow `mount()` or a slow API call does not
 * make the browser wait for the page. After the load, the state keeps the status, so the next events render
 * the children directly.
 *
 * When the render of the children throws, the error slot replaces them, and only for this
 * boundary. Without an error slot, the fallback stays: the event fails, and a streamed boundary
 * loads with an event.
 *
 * phemc and the Renderer know this component (see Phem\Rendering\SuspenseRenderer and
 * Phem\Rendering\SuspenseStream).
 */
final class Suspense
{
    /** Load the children with an event when the fallback comes into view, not in the response of the page. */
    #[Prop]
    public bool $lazy = false;
    #[Prop]
    public ?Html $children = null;
    /** What shows before the load. */
    #[Prop]
    public ?Html $fallback = null;
    /** What shows when the render of the children throws. */
    #[Prop]
    public ?Html $error = null;

    #[State]
    public SuspenseStatus $status = SuspenseStatus::Pending;

    /** The handler of the load event, from the browser, or from the stream of the page. */
    public function load(): void
    {
        if (SuspenseStatus::Pending === $this->status) {
            $this->status = SuspenseStatus::Loaded;
        }
    }
}
