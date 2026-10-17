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
        $vars['_mamu_caller'] = $caller;
        $html = $template->render($vars);

        return null === $key ? $html : Attributes::keyed($html, (string) $key);
    }
}
