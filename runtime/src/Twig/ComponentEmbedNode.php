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

namespace Mamu\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;

/**
 * The embed form of `{% component %}`. The body is an embedded template whose blocks are the
 * children and the named slots.
 *
 * - A template component: the embedded template extends the component template, so its blocks
 *   override the slot blocks (real Twig inheritance, as with `embed`).
 * - A function or class component: the Renderer receives the embedded template and renders
 *   each block lazily as a Slot value.
 *
 * The blocks run `{% with _mamu_caller only %}`, so they see the variables of the caller,
 * not the props of the component.
 */
#[YieldReady]
final class ComponentEmbedNode extends ComponentIncludeNode
{
    public function __construct(string $fqcn, string $kind, string $name, int $index, ?AbstractExpression $variables, ?AbstractExpression $key, int $lineno)
    {
        parent::__construct($fqcn, $kind, null, $variables, $key, $lineno);
        $this->setAttribute('name', $name);
        $this->setAttribute('index', $index);
    }

    protected function addGetTemplate(Compiler $compiler, string $template = ''): void
    {
        $compiler
            ->raw('$this->load(')
            ->string($this->getAttribute('name'))
            ->raw(', ')
            ->repr($this->getTemplateLine())
            ->raw(', ')
            ->repr($this->getAttribute('index'))
            ->raw(')');
    }

    protected function addSlots(Compiler $compiler): void
    {
        $this->addGetTemplate($compiler);
    }
}
