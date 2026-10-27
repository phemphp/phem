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

use Phem\Binding\PropError;
use Phem\Event;
use Phem\Handler\HandlerFingerprint;
use Phem\Handler\HandlerInvoker;
use Phem\Html;
use Phem\Manifest\ComponentDefinition;
use Phem\Manifest\Manifest;
use Phem\Metadata\ComponentMetadata;
use Phem\Node;
use Phem\Root;
use Phem\Slot;
use Phem\Stream\BroadcastKeys;
use Symfony\Component\HttpFoundation\Request;
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
    private ?RenderScope $scope = null;
    /** The render pass now: each render(), rerender() and detached() starts one (see Phem\Slot). */
    private int $pass = 0;
    /** The number of the passes so far: each pass has its own number. */
    private int $passes = 0;

    /**
     * @param iterable<ComponentRenderer> $renderers
     */
    public function __construct(
        private readonly Manifest $manifest,
        private readonly MarkupResolver $markup,
        private readonly iterable $renderers,
        private readonly HandlerInvoker $handlers = new HandlerInvoker(),
        private readonly HandlerFingerprint $fingerprints = new HandlerFingerprint(),
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ?RenderProfiler $profiler = null,
        private readonly ContextResolver $contexts = new ContextResolver(),
        private readonly BroadcastKeys $broadcasts = new BroadcastKeys(),
    ) {
    }

    /**
     * Renders the page of a tree. Call it again for a second pass with the new state.
     *
     * @param RenderScope|null $scope the fibers that render, or null for all of them
     */
    public function render(Tree $tree, ?RenderScope $scope = null): string
    {
        $tree->begin();
        $this->pass = ++$this->passes;

        return $this->within($tree, fn (): string => $this->renderComponent($tree, $tree->page, $tree->props, null, null, []), $scope);
    }

    /**
     * A new pass that renders only some fibers of the last pass, with their subtrees (see
     * UpdateScope): each fiber renders again with what its parent gave it in the last pass.
     * Its parent does not render.
     *
     * @param list<Fiber> $fibers fibers of the last pass, with no fiber in the subtree of another
     */
    public function rerender(Tree $tree, array $fibers, RenderScope $scope): void
    {
        $tree->begin();
        $this->pass = ++$this->passes;

        $this->within($tree, function () use ($tree, $fibers): void {
            foreach ($fibers as $fiber) {
                $fiber->refreshed = $tree->refreshes($fiber);
                $this->renderFiber($tree, $fiber, $this->manifest->component($fiber->fqcn) ?? throw new PropError(\sprintf('Unknown component "%s".', $fiber->fqcn)));
            }
        }, $scope);
    }

    /**
     * Runs an event handler of the last pass (see Tree::handler()) through the handler middleware.
     *
     * @return mixed the result of the handler (for example a Phem\RedirectTo)
     */
    public function dispatch(Tree $tree, \Closure $handler, Event $event, ?Request $request = null): mixed
    {
        return $this->within($tree, fn (): mixed => $this->handlers->invoke($handler, $event, $request));
    }

    /**
     * Runs a callback with a tree as the current tree, as dispatch() runs a handler: the work of
     * an #[Async] handler in a worker (see Phem\Async\AsyncHandler).
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function inside(Tree $tree, \Closure $callback): mixed
    {
        return $this->within($tree, $callback);
    }

    /**
     * Renders markup outside the page, for a push (see Phem\Stream\Streams): a component in it
     * renders with no fiber id, because the pages that get it have their own fibers. An event
     * handler, a `phem:bind`, a `phem:loading` and a `<Suspense>` throw: they need a fiber of
     * the page.
     */
    public function detached(Node $markup): string
    {
        $tree = new Tree(detached: true);
        $tree->begin();

        // Its own pass, inside the pass of the page (a push in mount()): the slots of the page
        // render one time in the pass of the page.
        $pass = $this->pass;
        $this->pass = ++$this->passes;

        try {
            $html = $this->within($tree, fn (): string => $this->markup->html($markup));
        } finally {
            $this->pass = $pass;
        }

        // Markup that rendered in the page before (a slot) has the fibers and the handlers of
        // this page.
        if (1 === preg_match('/\sdata-phem-(?:c|on-[\w-]+|bind|load|loading|progress)=/', $html)) {
            $this->notDetached('a component or a handler of this page (markup that rendered in the page before, such as a slot)', true);
        }

        return $html;
    }

    /** The render pass now (see Phem\Slot). */
    public function pass(): int
    {
        return $this->pass;
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
        $this->notDetached('an event handler');

        return $this->tree?->addHandler($handler, $this->fingerprints->of($handler));
    }

    /**
     * Registers a bound #[State] property of a component (`phem:bind={{ query }}`), or a path in
     * it (`['filters', 'status']`), and returns its key (see Tree::addBind()). The owner is the
     * component of the markup: the markup can render in a slot of another component, so it is not
     * always the current component. Null outside a page.
     *
     * @param string|list<mixed> $target the property, or the property and the keys of a path
     *
     * @throws \LogicException when the property is not a #[State] property of the component, or
     *                         when its value has no key of the path
     */
    public function addBind(string|array $target, ?object $owner = null): ?string
    {
        $this->notDetached('a "phem:bind"');

        $path = \is_array($target) ? $target : [$target];
        $property = array_shift($path);

        if (!\is_string($property)) {
            throw new \LogicException('"phem:bind" binds a #[State] property: a path starts with its name.');
        }

        $tree = $this->tree;
        $fiber = null !== $owner ? $tree?->fiberOf($owner) : $tree?->top();

        if (null === $tree || null === $fiber) {
            return null;
        }

        $instance = $fiber->instance;
        if (null === $instance || [] === array_filter($this->metadata->stateProperties($instance::class), static fn (\ReflectionProperty $p): bool => $p->getName() === $property)) {
            throw new \LogicException(\sprintf('"phem:bind" binds a #[State] property of the class component, but "%s" has no #[State] property "%s".', $fiber->fqcn, $property));
        }

        $keys = [];
        $value = [] !== $path ? new \ReflectionProperty($instance, $property)->getValue($instance) : null;
        foreach ($path as $segment) {
            if (\is_string($segment) || \is_int($segment)) {
                // As PHP keys: "3" is 3.
                $segment = array_key_first([$segment => true]);
            }

            if (!\is_array($value) || (!\is_int($segment) && !\is_string($segment)) || !\array_key_exists($segment, $value)) {
                throw new \LogicException(\sprintf('"phem:bind" binds a value of the #[State] property "%s::$%s", but it has no key %s. Give the property all the keys of its bound fields.', $fiber->fqcn, $property, implode('', array_map(static fn (mixed $k): string => '['.(\is_int($k) || \is_string($k) ? json_encode($k, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : get_debug_type($k)).']', [...$keys, $segment]))));
            }

            $keys[] = $segment;
            $value = $value[$segment];
        }

        return $tree->addBind($property, $fiber, $keys);
    }

    /**
     * Renders markup in the current fiber, for a boundary (see Phem\Suspense). When the render
     * throws, the fibers that it made are not in the tree (see Tree::drop()), with their state and
     * their handlers, and the exception is the result: the boundary renders something else.
     */
    public function catching(Html $markup): string|\Throwable
    {
        $tree = $this->tree;
        $fiber = $tree?->top();
        $count = \count($fiber->children ?? []);

        try {
            return (string) $markup;
        } catch (\Throwable $e) {
            if (null !== $tree && null !== $fiber) {
                foreach (array_splice($fiber->children, $count) as $child) {
                    $tree->drop($child);
                }
            }

            return $e;
        }
    }

    /** The fiber of a component instance in the current tree (see Tree::fiberOf()). Null outside a page. */
    public function fiberOf(object $instance): ?Fiber
    {
        return $this->tree?->fiberOf($instance);
    }

    /** The scope of a handler for `phem:loading` (see Tree::handlerScope()). */
    public function handlerScope(\Closure $handler): ?string
    {
        $this->notDetached('a "phem:loading" or a "phem:progress"');

        return $this->tree?->handlerScope($this->fingerprints->of($handler));
    }

    // -------------------------------------------------------------------------------------

    private function notDetached(string $what, bool $always = false): void
    {
        if ($always || (null !== $this->tree && $this->tree->detached)) {
            throw new \LogicException(\sprintf('A pushed stream is plain markup, but it has %s: it renders outside the page, so the browser cannot send its events. Push a refresh() of the component instead: each browser renders it again with a normal event.', $what));
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    private function within(Tree $tree, \Closure $callback, ?RenderScope $scope = null): mixed
    {
        $previous = [$this->tree, $this->scope];
        $this->tree = $tree;
        $this->scope = $scope;

        try {
            return $callback();
        } finally {
            [$this->tree, $this->scope] = $previous;
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

        $parent = $tree->top();
        $index = null !== $key ? '@'.$key : '#'.(null !== $parent ? $parent->nextIndex($fqcn) : $tree->nextRootIndex());
        $path = ($parent->path ?? '').'/'.$fqcn.$index;
        $id = Fiber::idOf($path);
        $fiber = $tree->fiber($id, static fn (?array $restored): Fiber => new Fiber($id, $path, $fqcn, $restored, $tree->query));

        // What the parent gave the fiber in the pass before, if the fiber got to that pass.
        $previous = $fiber->pass > 0 && $fiber->pass === $tree->pass - 1 ? $fiber->inputs() : null;
        $fiber->parent = $parent;
        $fiber->props = $props;
        $fiber->slots = $slots;
        $fiber->caller = $caller;

        if (null !== $parent) {
            $parent->children[] = $fiber;
        }

        $fiber->refreshed = $tree->refreshes($fiber);

        if (null !== $this->scope && !$this->scope->renders($fiber, $previous)) {
            $fiber->begin($tree->pass);
            $fiber->kept = true;
            $fiber->rendered('');

            if ($root) {
                $this->holdRoot($tree, $fiber);
            } elseif ($tree->shape->has($fiber->id) && null === $tree->shape->hashOf($fiber->id)) {
                // Around the root element in the last render (see TreeShape): so are its ancestors.
                for ($p = $fiber; null !== $p; $p = $p->parent) {
                    $p->holdsRoot = true;
                }
            }

            return $root ? RootElement::render($props, $tree->rootToken(), $fiber->wrapped()) : $fiber->output();
        }

        $this->renderFiber($tree, $fiber, $def);

        if ($tree->detached) {
            return $fiber->html;
        }

        return $root ? RootElement::render($props, $tree->rootToken(), $fiber->wrapped()) : $fiber->output();
    }

    /**
     * Renders a fiber with the props, slots and caller that it has, and keeps its HTML. Its id,
     * its parent and its place in the children of its parent do not change.
     */
    private function renderFiber(Tree $tree, Fiber $fiber, ComponentDefinition $def): void
    {
        if (null === $this->profiler) {
            $this->renderFiberNow($tree, $fiber, $def);

            return;
        }

        $this->profiler->profile($tree, $fiber, fn () => $this->renderFiberNow($tree, $fiber, $def));
    }

    private function renderFiberNow(Tree $tree, Fiber $fiber, ComponentDefinition $def): void
    {
        $root = Root::class === $def->fqcn;
        $renderer = $root ? null : $this->renderer($def);
        $fiber->begin($tree->pass);
        if ($root) {
            $this->holdRoot($tree, $fiber);
        }

        $tree->push($fiber);
        try {
            $slots = $this->slots($fiber);
            $out = null !== $renderer ? $renderer->render($fiber, $slots) : ($slots['children'] ?? null);
            // After the render: the props of a class component are set.
            $fiber->broadcast = $tree->detached ? [] : $this->broadcasts->ofFiber($fiber);
            // The markup is not rendered yet: the children read the contexts of this pass.
            $this->contexts->provide($fiber);
            $html = $this->markup->html($out);
        } finally {
            $tree->pop();
        }

        $fiber->rendered($html);
        // One strtr() for all the children: a replace for each child reads the HTML one time for
        // each child, so a list of components takes a time in the square of its length.
        $placeholders = [];
        foreach ($fiber->children as $child) {
            $output = $child->output();
            if ('' !== $output) {
                $placeholders[$output] = '<phem-c id="'.$child->id.'"></phem-c>';
            }
        }

        $fiber->ownHtml[$tree->pass] = strtr($html, $placeholders);
    }

    /** The page and the document around the root element have no fiber id. */
    private function holdRoot(Tree $tree, Fiber $fiber): void
    {
        $tree->rootFiber = $fiber;
        for ($p = $fiber->parent; null !== $p; $p = $p->parent) {
            $p->holdsRoot = true;
        }
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
     * the slots of the manifest: the props of a parent class that phemc does not see can be
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
            $out[$name] = new Slot(static fn (): string => $template->renderBlock($name, ['_phem_caller' => $caller]), $this->pass(...));
        }

        return $out;
    }
}
