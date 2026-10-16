<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Html;
use Rephp\Manifest\ComponentDefinition;

/**
 * Renders the body of one kind of fiber component (tag `rephp.component_renderer`). The
 * Renderer keeps the fibers; a ComponentRenderer only makes the output of one render.
 */
interface ComponentRenderer
{
    public function supports(ComponentDefinition $definition): bool;

    /**
     * @param array<string, Html> $slots
     *
     * @return mixed the output of the component: markup (Element, Html, a list of them), a string, or null
     */
    public function render(Fiber $fiber, array $slots): mixed;
}
