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
 * Measures the render of each fiber (see Phem\Profiler\Profile). The Renderer uses it only in
 * debug mode: without one, a render has no cost.
 */
interface RenderProfiler
{
    /**
     * Runs the render of one fiber. The child fibers render in it, so the time of a fiber
     * includes the time of its children.
     *
     * @param \Closure(): void $render renders the fiber in the current pass of the tree
     */
    public function profile(Tree $tree, Fiber $fiber, \Closure $render): void;
}
