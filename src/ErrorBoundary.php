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

/**
 * The `<ErrorBoundary>` tag: when the render of the children throws, the fallback shows in
 * place of the children, and only for this boundary, as in React:
 *
 *     use Phem\ErrorBoundary;
 *
 *     <ErrorBoundary>
 *         <ErrorBoundary.fallback><p>The quote is not available now.</p></ErrorBoundary.fallback>
 *         <StockQuote />
 *     </ErrorBoundary>
 *
 * The boundary catches the exceptions of the render only (also of `mount()`). An exception in an
 * event handler fails the event: the transaction rolls back, and the page keeps its state.
 *
 * The boundary has no state: each render tries the children again. When the fallback shows, the
 * children are not in the page, so they lose their state, and they mount again on the next
 * render that does not throw.
 *
 * phemc and the Renderer know this component (see Phem\Rendering\ErrorBoundaryRenderer).
 */
final class ErrorBoundary
{
    #[Prop]
    public ?Html $children = null;
    /** What shows when the render of the children throws. */
    #[Prop]
    public ?Html $fallback = null;
}
