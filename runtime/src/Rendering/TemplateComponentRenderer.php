<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Rephp\Binding\PropBinder;
use Rephp\Dom\Attributes;
use Twig\Template;

/**
 * Renders a template component: plain Twig, with no fiber and no PHP call.
 */
final class TemplateComponentRenderer
{
    public function __construct(private readonly PropBinder $props = new PropBinder())
    {
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller the Twig context of the caller (for slots)
     */
    public function render(Template $template, string $fqcn, array $props, mixed $key, array $caller): string
    {
        $vars = $this->props->templateContext($fqcn, $props);
        $vars['_rhp_caller'] = $caller;
        $html = $template->render($vars);

        return null === $key ? $html : Attributes::keyed($html, (string) $key);
    }
}
