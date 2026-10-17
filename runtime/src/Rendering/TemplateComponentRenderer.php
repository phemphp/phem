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

use Mamu\Binding\PropBinder;
use Mamu\Dom\Attributes;
use Mamu\Slot;
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
     * With children or slots, $template is the embedded template of the caller: its blocks
     * override the slot blocks of the component template. The slots are also props, so the
     * template can test them (`{% if footer %}`) and a required slot is given.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller the Twig context of the caller (for slots)
     * @param list<string>         $slots  the slots that the caller gives
     */
    public function render(Template $template, string $fqcn, array $props, mixed $key, array $caller, array $slots = []): string
    {
        $given = [];
        foreach ($slots as $name) {
            $given[$name] = new Slot(static fn (): string => $template->renderBlock($name, ['_mamu_caller' => $caller]));
        }
        $vars = $this->props->templateContext($fqcn, $props, $given);
        $vars['_mamu_caller'] = $caller;
        $html = $template->render($vars);

        return null === $key ? $html : Attributes::keyed($html, (string) $key);
    }
}
