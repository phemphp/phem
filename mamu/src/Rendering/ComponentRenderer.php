<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Rendering;

use Mamu\Html;
use Mamu\Manifest\ComponentDefinition;

/**
 * Renders the body of one kind of fiber component (tag `mamu.component_renderer`). The
 * Renderer keeps the fibers; a ComponentRenderer only makes the output of one render.
 */
interface ComponentRenderer
{
    public function supports(ComponentDefinition $definition): bool;

    /**
     * @param array<string, Html> $slots
     *
     * @return mixed the output of the component: markup (Element, Html, a list of them), a string (text: it is escaped), or null
     */
    public function render(Fiber $fiber, array $slots): mixed;
}
