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
 * Selects the fibers that render in a pass. A fiber that does not render is kept: the browser
 * keeps its HTML, and its subtree does not render either.
 *
 * With no scope, all the fibers render.
 */
interface RenderScope
{
    /**
     * @param Fiber            $fiber    the fiber, with the props, slots and caller of this pass
     * @param FiberInputs|null $previous what the parent gave the fiber in the pass before, or null when the fiber did not get to that pass
     */
    public function renders(Fiber $fiber, ?FiberInputs $previous): bool;
}
