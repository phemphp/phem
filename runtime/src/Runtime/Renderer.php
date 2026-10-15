<?php

declare(strict_types=1);

namespace Rephp\Runtime;

use Rephp\Context;
use Rephp\Html;
use Rephp\Ref;
use Rephp\Runtime;
use Rephp\Slot;
use Twig\Template;

/**
 * Renders a page as a tree of fibers, for one request.
 *
 * The server keeps no state between requests. The browser sends the state of all fibers
 * (signed); the Renderer restores it, renders, runs an event handler, renders again, and
 * reports which fibers changed.
 */
final class Renderer
{
    private static ?self $current = null;

    /** @var array<string, Fiber> */
    private array $fibers = [];
    /** @var list<Fiber> */
    private array $stack = [];
    /** @var array<string, \Closure> */
    private array $handlers = [];
    private int $pass = 0;
    private int $rootIndex = 0;
    private ?Fiber $root = null;

    /**
     * @param array<string, array<string, mixed>> $state fiber id → exported state
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly array $state = [],
    ) {
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /** The current renderer and fiber, for a hook. */
    public static function fiberFor(string $hook): Fiber
    {
        $fiber = self::$current?->top();
        if (null === $fiber) {
            throw new HookError(\sprintf('%s() can only be called while a component renders (Rules of Hooks).', $hook));
        }

        return $fiber;
    }

    private function top(): ?Fiber
    {
        return $this->stack[\count($this->stack) - 1] ?? null;
    }

    /**
     * Renders the page component. Call it again to render a second pass with the new state.
     */
    public function render(string $fqcn): string
    {
        ++$this->pass;
        $this->rootIndex = 0;
        $this->handlers = [];
        $previous = self::$current;
        self::$current = $this;
        try {
            $this->renderComponent($fqcn, [], null, null, []);
        } finally {
            self::$current = $previous;
        }
        $this->root = $this->fibers[Fiber::idOf('/'.$fqcn.'#0')];

        return $this->root->wrapped();
    }

    public function handler(string $id): ?\Closure
    {
        return $this->handlers[$id] ?? null;
    }

    /** Runs a handler with this renderer as the current one (hooks may read context). */
    public function run(\Closure $handler, mixed ...$args): void
    {
        $previous = self::$current;
        self::$current = $this;
        try {
            $handler(...$args);
        } finally {
            self::$current = $previous;
        }
    }

    /** @return array<string, array<string, mixed>> the state of all mounted fibers */
    public function exportState(): array
    {
        $out = [];
        foreach ($this->fibers as $id => $fiber) {
            if ($fiber->pass === $this->pass && [] !== $s = $fiber->export()) {
                $out[$id] = $s;
            }
        }

        return $out;
    }

    /**
     * The fibers to update after a second pass: the changed fibers whose ancestors did not change.
     *
     * @return list<Fiber>
     */
    public function changedFibers(): array
    {
        $changed = [];
        foreach ($this->fibers as $id => $f) {
            if ($f->pass === $this->pass && ($f->ownHtml[$this->pass] ?? null) !== ($f->ownHtml[$this->pass - 1] ?? null)) {
                $changed[$id] = $f;
            }
        }
        $out = [];
        foreach ($changed as $f) {
            for ($p = $f->parent; null !== $p; $p = $p->parent) {
                if (isset($changed[$p->id])) {
                    continue 2;
                }
            }
            $out[] = $f;
        }

        return $out;
    }

    public function registerHandler(\Closure $handler): string
    {
        $fiber = $this->top();
        $id = null === $fiber ? 'x.'.\count($this->handlers) : $fiber->id.'.'.$fiber->handlerIndex++;
        $this->handlers[$id] = $handler;

        return $id;
    }

    // --- Called by compiled templates -------------------------------------------------

    /**
     * Renders a function or class component (`{% component %}`).
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller the Twig context of the caller (for slots)
     */
    public static function component(string $fqcn, array $props, mixed $key, ?Template $slots, array $caller): string
    {
        if (null === self::$current) {
            // Rendered outside a page (for example `Rt::render()` in a test): a throwaway tree.
            $r = new self(Runtime::get());
            self::$current = $r;
            try {
                return $r->renderComponent($fqcn, $props, $key, $slots, $caller);
            } finally {
                self::$current = null;
            }
        }

        return self::$current->renderComponent($fqcn, $props, $key, $slots, $caller);
    }

    /**
     * Renders a template component: plain Twig, no fiber.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     */
    public static function template(Template $template, string $fqcn, array $props, mixed $key, array $caller): string
    {
        $vars = Props::templateContext($fqcn, $props);
        $vars['_rhp_caller'] = $caller;
        $html = $template->render($vars);
        if (null !== $key) {
            $html = self::withKey($html, (string) $key);
        }

        return $html;
    }

    /** Adds `data-rh-key` to the first element of an HTML fragment. */
    private static function withKey(string $html, string $key): string
    {
        $attr = ' data-rh-key="'.htmlspecialchars($key, \ENT_QUOTES, 'UTF-8').'"';

        return preg_replace('/^(\s*<[a-zA-Z][\w:-]*)/', '$1'.$attr, $html, 1) ?? $html;
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $caller
     */
    private function renderComponent(string $fqcn, array $props, mixed $key, ?Template $slots, array $caller): string
    {
        $def = $this->runtime->component($fqcn) ?? throw new PropError(\sprintf('Unknown component "%s".', $fqcn));
        $parent = $this->top();
        $index = null !== $key ? '@'.$key : '#'.(null !== $parent ? $parent->nextIndex($fqcn) : $this->rootIndex++);
        $path = ($parent?->path ?? '').'/'.$fqcn.$index;
        $id = Fiber::idOf($path);
        $fiber = $this->fibers[$id] ??= new Fiber($id, $path, $fqcn, (string) $def['kind'], $this->state[$id] ?? []);
        $fiber->parent = $parent;
        $fiber->props = $props;
        $fiber->slots = $slots;
        $fiber->caller = $caller;

        $fiber->begin($this->pass);
        $this->stack[] = $fiber;
        try {
            $slotValues = $this->slots($fiber, $def['slots'] ?? []);
            $out = 'class' === $fiber->kind
                ? $this->renderClass($fiber, $slotValues)
                : $fqcn(...Props::arguments($fqcn, $props, $slotValues));
            $html = (string) ($out ?? '');
            $fiber->end();
        } finally {
            array_pop($this->stack);
        }

        $fiber->html = $html;
        $own = $html;
        foreach ($fiber->children as $child) {
            $own = str_replace($child->wrapped(), '<rh-c id="'.$child->id.'"></rh-c>', $own);
        }
        $fiber->ownHtml[$this->pass] = $own;
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
                $out[$name] = new Slot(static fn () => $template->renderBlock($name, ['_rhp_caller' => $caller]));
            }
        }

        return $out;
    }

    /** @param array<string, Html> $slots */
    private function renderClass(Fiber $fiber, array $slots): mixed
    {
        if (null === $fiber->instance) {
            $class = $fiber->fqcn;
            $fiber->instance = new $class();
            foreach (Fiber::stateProperties($class) as $prop) {
                if (\array_key_exists($prop->getName(), $fiber->restored['c'] ?? [])) {
                    $prop->setValue($fiber->instance, $fiber->restored['c'][$prop->getName()]);
                }
            }
        }
        Props::assign($fiber->instance, $fiber->props, $slots);

        return $fiber->instance->render();
    }

    // --- Hooks --------------------------------------------------------------------------

    /** @return array{0: mixed, 1: \Closure} */
    public static function useState(mixed $initial): array
    {
        $f = self::fiberFor('useState');
        $i = $f->hook('state');
        if (!\array_key_exists($i, $f->hooks)) {
            $f->hooks[$i] = \array_key_exists($i, $f->restored['h'] ?? [])
                ? $f->restored['h'][$i]
                : ($initial instanceof \Closure ? $initial() : $initial);
        }
        $set = $f->setters[$i] ??= static function (mixed $next) use ($f, $i): void {
            $f->hooks[$i] = $next instanceof \Closure ? $next($f->hooks[$i]) : $next;
        };

        return [$f->hooks[$i], $set];
    }

    /** @return array{0: mixed, 1: \Closure} */
    public static function useReducer(callable $reducer, mixed $initial, ?callable $init = null): array
    {
        $f = self::fiberFor('useReducer');
        $i = $f->hook('reducer');
        if (!\array_key_exists($i, $f->hooks)) {
            $f->hooks[$i] = \array_key_exists($i, $f->restored['h'] ?? [])
                ? $f->restored['h'][$i]
                : (null !== $init ? $init($initial) : $initial);
        }
        $f->reducers[$i] = $reducer(...);
        $dispatch = $f->setters[$i] ??= static function (mixed $action) use ($f, $i): void {
            $f->hooks[$i] = ($f->reducers[$i])($f->hooks[$i], $action);
        };

        return [$f->hooks[$i], $dispatch];
    }

    public static function useRef(mixed $initial): Ref
    {
        $f = self::fiberFor('useRef');
        $i = $f->hook('ref');

        return $f->hooks[$i] ??= new Ref(\array_key_exists($i, $f->restored['h'] ?? []) ? $f->restored['h'][$i] : $initial);
    }

    /** @param list<mixed>|null $deps */
    public static function useMemo(callable $compute, ?array $deps): mixed
    {
        $f = self::fiberFor('useMemo');
        $i = $f->hook('memo');
        $slot = $f->hooks[$i] ?? null;
        if (null === $slot || null === $deps || $slot[0] !== $deps) {
            $slot = $f->hooks[$i] = [$deps, $compute()];
        }

        return $slot[1];
    }

    public static function useId(): string
    {
        $f = self::fiberFor('useId');

        return ':'.$f->id.'-'.$f->hook('id').':';
    }

    public static function useContext(Context $context): mixed
    {
        $f = self::fiberFor('useContext');
        $f->hook('context');
        $key = spl_object_id($context);
        for ($p = $f; null !== $p; $p = $p->parent) {
            if (\array_key_exists($key, $p->contextValues)) {
                return $p->contextValues[$key];
            }
        }

        return $context->default;
    }

    public static function provide(Context $context, mixed $value): void
    {
        self::fiberFor('Provider')->contextValues[spl_object_id($context)] = $value;
    }
}
