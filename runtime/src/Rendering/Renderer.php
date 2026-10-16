<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Psr\Container\ContainerInterface;
use Rephp\Attribute\Transactional;
use Rephp\Element;
use Rephp\Event;
use Rephp\Html;
use Rephp\HtmlList;
use Rephp\Manifest;
use Rephp\Slot;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;
use Twig\Template;

/**
 * Renders component trees.
 *
 * Class components are services: the container makes a new instance for each fiber (the
 * constructor gets the dependencies), then the Renderer writes the props, restores the state
 * or calls `mount()`, and calls `render()`. Function components are called with their props.
 * Template components render as plain Twig.
 *
 * The event handlers of a `#[Transactional]` component run in a transaction of the
 * TransactionRunner (Doctrine ORM, when DoctrineBundle is installed).
 *
 * While a tree renders, it is the current tree of the Renderer: the compiled templates call
 * back into it (through the Twig runtime) for nested components and event handlers.
 */
final class Renderer implements ResetInterface
{
    private ?Tree $tree = null;

    /**
     * @param ContainerInterface $components class component FQCN => service (not shared)
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly Manifest $manifest,
        private readonly ContainerInterface $components,
        private readonly PropBinder $props = new PropBinder(),
        private readonly ?TransactionRunner $transactions = null,
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
     * Runs an event handler of the last pass. When the handler returns a RedirectResponse,
     * its URL goes to `$tree->redirect`.
     *
     * @return bool false when the tree has no handler with this id
     */
    public function dispatch(Tree $tree, string $handlerId, Event $event): bool
    {
        $handler = $tree->handler($handlerId);
        if (null === $handler) {
            return false;
        }
        $call = static fn (): mixed => $handler($event);
        $result = $this->within($tree, self::isTransactional($handler) ? fn (): mixed => $this->transactions()->run($call) : $call);
        if ($result instanceof RedirectResponse) {
            $tree->redirect = $result->getTargetUrl();
        }

        return true;
    }

    /**
     * The state that the browser keeps. The `_path` key keeps the route values of the page for
     * the events (a fiber id starts with `rh`). The events find the entities again from them.
     *
     * @return array<string, mixed> fiber id => state, for the fibers of the last pass
     */
    public function exportState(Tree $tree): array
    {
        $out = [] !== $tree->values ? ['_path' => $tree->values] : [];
        foreach ($tree->mounted() as $fiber) {
            if (null !== $fiber->instance && [] !== $state = $this->props->exportState($fiber->instance)) {
                $out[$fiber->id] = $state;
            }
        }

        return $out;
    }

    /**
     * Renders markup values: an Element becomes Html, and a list of markup becomes an HtmlList.
     * Other values do not change.
     */
    public function resolve(mixed $value): mixed
    {
        if ($value instanceof Element) {
            return new Html($this->twig->render(Manifest::templateName($value->template), array_map($this->resolve(...), $value->vars)));
        }
        if (\is_array($value)) {
            $value = array_map($this->resolve(...), $value);

            return HtmlList::accepts($value) ? new HtmlList($value) : $value;
        }

        return $value;
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

    /**
     * Renders a template component: plain Twig, no fiber.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     */
    public function template(Template $template, string $fqcn, array $props, mixed $key, array $caller): string
    {
        $vars = $this->props->templateContext($fqcn, $props);
        $vars['_rhp_caller'] = $caller;
        $html = $template->render($vars);

        return null === $key ? $html : self::withKey($html, (string) $key);
    }

    /** Registers an event handler of the current fiber. Null outside a page. */
    public function addHandler(\Closure $handler): ?string
    {
        return $this->tree?->addHandler($handler);
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
        $parent = $tree->top();
        $index = null !== $key ? '@'.$key : '#'.(null !== $parent ? $parent->nextIndex($fqcn) : $tree->rootIndex++);
        $path = ($parent?->path ?? '').'/'.$fqcn.$index;
        $id = Fiber::idOf($path);
        $fiber = $tree->fibers[$id] ??= new Fiber($id, $path, $fqcn, (string) $def['kind'], $tree->restored($id));
        $fiber->parent = $parent;
        $fiber->props = $props;
        $fiber->slots = $slots;
        $fiber->caller = $caller;

        $fiber->begin($tree->pass);
        $tree->push($fiber);
        try {
            $slotValues = $this->slots($fiber, $def['slots'] ?? []);
            $out = 'class' === $fiber->kind
                ? $this->renderClass($fiber, $slotValues)
                : $fqcn(...$this->props->arguments($fqcn, $props, $slotValues));
            $html = (string) ($this->resolve($out) ?? '');
        } finally {
            $tree->pop();
        }

        $fiber->html = $html;
        $own = $html;
        foreach ($fiber->children as $child) {
            $own = str_replace($child->wrapped(), '<rh-c id="'.$child->id.'"></rh-c>', $own);
        }
        $fiber->ownHtml[$tree->pass] = $own;
        if (null !== $parent) {
            $parent->children[] = $fiber;
        }

        return $fiber->wrapped();
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, Html>
     */
    private function slots(Fiber $fiber, array $names): array
    {
        $template = $fiber->slots;
        if (null === $template) {
            return [];
        }
        $blocks = $template->getBlockNames([]);
        $caller = $fiber->caller;
        $out = [];
        foreach ($names as $name) {
            if (\in_array($name, $blocks, true)) {
                $out[$name] = new Slot(static fn (): string => $template->renderBlock($name, ['_rhp_caller' => $caller]));
            }
        }

        return $out;
    }

    /**
     * The first time a fiber renders in a request, the container makes its instance. Then the
     * state that the browser sent back is restored; without one, `mount()` initializes it.
     *
     * @param array<string, Html> $slots
     */
    private function renderClass(Fiber $fiber, array $slots): mixed
    {
        $component = $fiber->instance;
        if (null === $component) {
            $component = $fiber->instance = $this->create($fiber->fqcn);
            $this->props->assign($component, $fiber->props, $slots);
            if (null !== $fiber->restored) {
                $this->props->restoreState($component, $fiber->restored);
            } elseif (method_exists($component, 'mount')) {
                $component->mount();
            }
        } else {
            $this->props->assign($component, $fiber->props, $slots);
        }

        return $component->render();
    }

    private function create(string $fqcn): object
    {
        if (!$this->components->has($fqcn)) {
            throw new \LogicException(\sprintf('The component "%s" is not a service. Run `bin/console cache:clear` after `rhpc build`.', $fqcn));
        }

        return $this->components->get($fqcn);
    }

    private function transactions(): TransactionRunner
    {
        return $this->transactions ?? throw new \LogicException('A #[Transactional] component needs Doctrine ORM: install doctrine/orm and doctrine/doctrine-bundle.');
    }

    /**
     * The component of a handler is the object of its closure: a method (`$this->add(...)`) or
     * a closure in `render()`. A handler that a parent passes as a prop runs with the parent.
     */
    private static function isTransactional(\Closure $handler): bool
    {
        $component = new \ReflectionFunction($handler)->getClosureThis();

        return null !== $component && [] !== new \ReflectionObject($component)->getAttributes(Transactional::class);
    }

    /** Adds `data-rh-key` to the first element of an HTML fragment. */
    private static function withKey(string $html, string $key): string
    {
        $attr = ' data-rh-key="'.htmlspecialchars($key, \ENT_QUOTES, 'UTF-8').'"';

        return preg_replace('/^(\s*<[a-zA-Z][\w:-]*)/', '$1'.$attr, $html, 1) ?? $html;
    }
}
