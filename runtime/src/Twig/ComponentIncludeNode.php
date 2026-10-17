<?php

declare(strict_types=1);

namespace Mamu\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\IncludeNode;

/**
 * The include form of `{% component %}`.
 *
 * - A template component renders its template like `include ... only`, with the props and
 *   the parameter defaults as the context. There is no PHP call.
 * - A function or class component goes through the Renderer (services, state, handlers).
 *
 * Both call the MamuRuntime of the Twig environment.
 */
#[YieldReady]
class ComponentIncludeNode extends IncludeNode
{
    public function __construct(string $fqcn, string $kind, ?string $template, ?AbstractExpression $variables, ?AbstractExpression $key, int $lineno)
    {
        parent::__construct(new ConstantExpression($template ?? 'not_used', $lineno), $variables, true, false, $lineno);
        $this->setAttribute('fqcn', $fqcn);
        $this->setAttribute('kind', $kind);
        if (null !== $key) {
            $this->setNode('key', $key);
        }
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->addDebugInfo($this);
        $compiler->write('yield $this->env->getRuntime(')->string(MamuRuntime::class)->raw(')');
        if ('template' === $this->getAttribute('kind')) {
            $compiler->raw('->template(');
            $this->addGetTemplate($compiler);
            $compiler->raw(', ');
        } else {
            $compiler->raw('->component(');
        }
        $compiler->string($this->getAttribute('fqcn'))->raw(', ');
        $this->addProps($compiler);
        $compiler->raw(', ');
        $this->addKey($compiler);
        if ('template' !== $this->getAttribute('kind')) {
            $compiler->raw(', ');
            $this->addSlots($compiler);
        }
        $compiler->raw(", \$context);\n");
    }

    protected function addSlots(Compiler $compiler): void
    {
        $compiler->raw('null');
    }

    protected function addProps(Compiler $compiler): void
    {
        if ($this->hasNode('variables')) {
            $compiler->raw('\Twig\Extension\CoreExtension::toArray(')->subcompile($this->getNode('variables'))->raw(')');
        } else {
            $compiler->raw('[]');
        }
    }

    protected function addKey(Compiler $compiler): void
    {
        if ($this->hasNode('key')) {
            $compiler->subcompile($this->getNode('key'));
        } else {
            $compiler->raw('null');
        }
    }
}
