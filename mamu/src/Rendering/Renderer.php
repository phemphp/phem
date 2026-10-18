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

use Mamu\Binding\PropError;
use Mamu\Event;
use Mamu\Handler\HandlerFingerprint;
use Mamu\Handler\HandlerInvoker;
use Mamu\Html;
use Mamu\Manifest\ComponentDefinition;
use Mamu\Manifest\Manifest;
use Mamu\Root;
use Mamu\Slot;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Template;

/**
 * Renders component trees.
 *
 * The Renderer keeps the fibers: their ids, their props and slots, and the HTML of each pass.
 * A ComponentRenderer renders the body of each kind of component (class or function). The
 * template components render as plain Twig (TemplateComponentRenderer), with no fiber.
 *
 * While a tree renders, it is the current tree of the Renderer: the compiled templates call
 * back into it (through the Twig runtime) for nested components and event handlers.
 */
final class Renderer implements ResetInterface
{
    private ?Tree $tree = null;

    /**
     * @param iterable<ComponentRenderer> $renderers
     */
    public function __construct(
        private readonly Manifest $manifest,
        private readonly MarkupResolver $markup,
        private readonly iterable $renderers,
        private readonly HandlerInvoker $handlers = new HandlerInvoker(),
        private readonly HandlerFingerprint $fingerprints = new HandlerFingerprint(),
    ) {
    }

    /**
     * Renders the page of a tree. Call it again for a second pass with the new state.
     */
    public function render(Tree $tree): string
    {
        $tree->begin();

        return $this->within($tree, fn (): string => $this->renderComponent($tree, $tree->page, $tree->props, null, null, []));
    }

    /**
     * Runs an event handler of the last pass (see Tree::handler()) through the handler middleware.
     *
     * @return mixed the result of the handler (for example a RedirectResponse)
     */
    public function dispatch(Tree $tree, \Closure $handler, Event $event): mixed
    {
        return $this->within($tree, fn (): mixed => $this->handlers->invoke($handler, $event));
    }

    public function reset(): void
    {
        $this->tree = null;
    }

    // --- Called by the compiled templates (through the Twig runtime) ---------------------

    /**
     * Renders a function or class component (`{% component %}`).
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller the Twig context of the caller (for slots)
     */
    public function component(string $fqcn, array $props, mixed $key, ?Template $slots, array $caller): string
    {
        if (null === $this->tree) {
            // A component in a Twig template outside a page: a static render in its own tree.
            $tree = new Tree();
            $tree->begin();

            return $this->within($tree, fn (): string => $this->renderComponent($tree, $fqcn, $props, $key, $slots, $caller));
        }

        return $this->renderComponent($this->tree, $fqcn, $props, $key, $slots, $caller);
    }

    /** Registers an event handler of the current fiber. Null outside a page. */
    public function addHandler(\Closure $handler): ?string
    {
        return $this->tree?->addHandler($handler, $this->fingerprints->of($handler));
    }

    // -------------------------------------------------------------------------------------

    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    private function within(Tree $tree, \Closure $callback): mixed
    {
        $previous = $this->tree;
        $this->tree = $tree;
        try {
            return $callback();
        } finally {
            $this->tree = $previous;
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     */
    private function renderComponent(Tree $tree, string $fqcn, array $props, mixed $key, ?Template $slots, array $caller): string
    {
        $def = $this->manifest->component($fqcn) ?? throw new PropError(\sprintf('Unknown component "%s".', $fqcn));
        $root = Root::class === $def->fqcn;
        if ($root && '' === $tree->page) {
            throw new \LogicException('A <Root> is the root element of a page: render it in the document of a page, not in a Twig template.');
        }
        if ($root && null !== $tree->rootFiber) {
            throw new \LogicException(\sprintf('The page "%s" renders more than one <Root>: render one, in the document of the page.', $tree->page));
        }
        $renderer = $root ? null : $this->renderer($def);
        $parent = $tree->top();
        $index = null !== $key ? '@'.$key : '#'.(null !== $parent ? $parent->nextIndex($fqcn) : $tree->nextRootIndex());
        $path = ($parent->path ?? '').'/'.$fqcn.$index;
        $id = Fiber::idOf($path);
        $fiber = $tree->fiber($id, static fn (?array $restored): Fiber => new Fiber($id, $path, $fqcn, $restored));
        $fiber->parent = $parent;
        $fiber->props = $props;
        $fiber->slots = $slots;
        $fiber->caller = $caller;

        $fiber->begin($tree->pass);
        if ($root) {
            // The page and the document around the root element are not in a <mamu-c>.
            $tree->rootFiber = $fiber;
            for ($p = $parent; null !== $p; $p = $p->parent) {
                $p->holdsRoot = true;
            }
        }
        $tree->push($fiber);
        try {
            $slots = $this->slots($fiber);
            $out = null !== $renderer ? $renderer->render($fiber, $slots) : ($slots['children'] ?? null);
            $html = $this->markup->html($out);
        } finally {
            $tree->pop();
        }

        $fiber->html = $html;
        $own = $html;
        foreach ($fiber->children as $child) {
            $own = str_replace($child->output(), '<mamu-c id="'.$child->id.'"></mamu-c>', $own);
        }
        $fiber->ownHtml[$tree->pass] = $own;
        if (null !== $parent) {
            $parent->children[] = $fiber;
        }

        return $root ? RootElement::render($props, $tree->rootToken(), $fiber->wrapped()) : $fiber->output();
    }

    private function renderer(ComponentDefinition $definition): ComponentRenderer
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($definition)) {
                return $renderer;
            }
        }

        throw new \LogicException(\sprintf('No component renderer renders the %s component "%s".', $definition->kind->value, $definition->fqcn));
    }

    /**
     * The slots that the caller gives: each block of its embedded template. They are not only
     * the slots of the manifest: the props of a parent class that mamuc does not see can be
     * slots too. An unknown slot is a PropError of the props.
     *
     * @return array<string, Html>
     */
    private function slots(Fiber $fiber): array
    {
        $template = $fiber->slots;
        if (null === $template) {
            return [];
        }
        $caller = $fiber->caller;
        $out = [];
        foreach ($template->getBlockNames([]) as $name) {
            $out[$name] = new Slot(static fn (): string => $template->renderBlock($name, ['_mamu_caller' => $caller]));
        }

        return $out;
    }
}
