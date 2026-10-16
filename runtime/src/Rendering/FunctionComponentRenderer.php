<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Binding\PropBinder;
use Rephp\Manifest\ComponentDefinition;
use Rephp\Manifest\ComponentKind;

/**
 * Renders a function component: a call with the props (and slots) as named arguments.
 *
 * A template component is also a function. In a template, `{% component %}` renders it as
 * plain Twig (TemplateComponentRenderer); as the root of a page, its function renders here.
 */
final class FunctionComponentRenderer implements ComponentRenderer
{
    public function __construct(private readonly PropBinder $props = new PropBinder())
    {
    }

    public function supports(ComponentDefinition $definition): bool
    {
        return ComponentKind::FunctionComponent === $definition->kind || ComponentKind::TemplateComponent === $definition->kind;
    }

    public function render(Fiber $fiber, array $slots): mixed
    {
        return ($fiber->fqcn)(...$this->props->arguments($fiber->fqcn, $fiber->props, $slots));
    }
}
