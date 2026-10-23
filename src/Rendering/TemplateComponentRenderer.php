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

use Phem\Binding\PropBinder;
use Phem\Dom\Attributes;
use Phem\Slot;
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
     * With children or slots, $template is the embedded template of the caller, and its
     * blocks are the slots. The slots are props, so the template can test them
     * (`{% if footer %}`) and a required slot is given. The component template (the parent of
     * $template) renders, not $template: its slot blocks print the slot props, so each slot
     * renders once. With block inheritance, a slot that the template tests renders two times.
     *
     * @param array<string, mixed>   $props
     * @param array<string, mixed>   $caller the Twig context of the caller (for slots)
     * @param list<string>           $slots  the slots that the caller gives
     * @param (\Closure(): int)|null $pass   the render pass now (see Phem\Slot)
     */
    public function render(Template $template, string $fqcn, array $props, mixed $key, array $caller, array $slots = [], ?\Closure $pass = null): string
    {
        $given = [];
        foreach ($slots as $name) {
            $given[$name] = new Slot(static fn (): string => $template->renderBlock($name, ['_phem_caller' => $caller]), $pass);
        }

        $vars = $this->props->templateContext($fqcn, $props, $given);
        $vars['_phem_caller'] = $caller;
        $parent = [] !== $slots ? $template->getParent($vars) : false;
        $html = ($parent ?: $template)->render($vars);

        return null === $key ? $html : Attributes::keyed($html, (string) $key);
    }
}
