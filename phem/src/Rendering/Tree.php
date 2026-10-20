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

use Phem\State\TreeShape;

/**
 * The fibers of one page for one request.
 *
 * The server needs no state between requests. The browser keeps the state of all fibers and the
 * shape of the last render (signed), and an event gives them to the server (or the server finds
 * them in the StateCache); a Tree starts from them. For an event, the Renderer
 * renders the fibers on the path to the handler, runs the handler, renders the fibers that can
 * change, and the tree reports which fibers changed since the last render (see EventRenderer).
 */
final class Tree
{
    public int $pass = 0;
    /** The fiber of the `<Root>` of the last pass (see Phem\Root). */
    public ?Fiber $rootFiber = null;

    /** @var array<string, Fiber> */
    private array $fibers = [];
    private int $rootIndex = 0;
    /** @var list<Fiber> */
    private array $stack = [];
    /** @var array<string, \Closure> */
    private array $handlers = [];
    /** @var array<string, int> handler id => the number of handlers with this id */
    private array $handlerCounts = [];
    /** @var array<string, array{string, string, list<int|string>}> the bound properties of the pass: key => [fiber id, property, path] */
    private array $binds = [];
    private ?string $rootToken = null;

    /**
     * @param string                              $page   the page component, or '' for a tree without a page
     * @param array<string, array<string, mixed>> $state  fiber id => state that the browser sent back
     * @param array<string, mixed>                $props  the props of the page: its #[Path] route values (an entity for an entity prop)
     * @param array<string, mixed>                $values the route values of the #[Path] props, as the route gives them (an identifier or criteria for an entity prop)
     * @param array<array-key, mixed>             $query  the query string of the page request, for the #[Url] properties of a new fiber (see Phem\State\UrlQuery)
     * @param TreeShape                           $shape  the shape of the last render, in the browser
     */
    public function __construct(
        public readonly string $page = '',
        private readonly array $state = [],
        public readonly array $props = [],
        public readonly array $values = [],
        public readonly array $query = [],
        public readonly TreeShape $shape = new TreeShape(),
    ) {
    }

    /** Starts a render pass. */
    public function begin(): void
    {
        ++$this->pass;
        $this->rootIndex = 0;
        $this->rootFiber = null;
        $this->handlers = [];
        $this->handlerCounts = [];
        $this->binds = [];
    }

    /**
     * The fiber with this id. A new fiber starts from the state that the browser sent back.
     *
     * @param \Closure(?array<string, mixed>): Fiber $create makes the fiber from its restored state
     */
    public function fiber(string $id, \Closure $create): Fiber
    {
        return $this->fibers[$id] ??= $create($this->state[$id] ?? null);
    }

    /** The index of the next component at the root of the tree. */
    public function nextRootIndex(): int
    {
        return $this->rootIndex++;
    }

    public function top(): ?Fiber
    {
        return $this->stack[\count($this->stack) - 1] ?? null;
    }

    public function push(Fiber $fiber): void
    {
        $this->stack[] = $fiber;
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * Registers an event handler of the current fiber. Its id is stable between requests: the
     * fiber and the fingerprint of the handler (see HandlerFingerprint), not its position. Only
     * handlers with the same fingerprint (the same method twice) get a number in their order.
     */
    public function addHandler(\Closure $handler, string $fingerprint): string
    {
        $base = ($this->top()->id ?? 'x').'.'.$fingerprint;
        $n = $this->handlerCounts[$base] = ($this->handlerCounts[$base] ?? 0) + 1;
        $id = 1 === $n ? $base : $base.'~'.$n;
        $this->handlers[$id] = $handler;

        return $id;
    }

    /**
     * The scope of a handler for `phem:loading`: the current fiber and the fingerprint of the
     * handler, with no number. It does not register the handler.
     */
    public function handlerScope(string $fingerprint): string
    {
        return ($this->top()->id ?? 'x').'.'.$fingerprint;
    }

    public function handler(string $id): ?\Closure
    {
        return $this->handlers[$id] ?? null;
    }

    /**
     * Registers a bound #[State] property of a fiber (`phem:bind={{ query }}`), the current fiber
     * by default, or a path in it (`phem:bind={{ filters.status }}`). Its key is
     * "<fiber>.<property>", with a JSON key in brackets for each segment of the path
     * (`<fiber>.rows[3]["name"]`): the browser sends the new value with this key (see BoundValues).
     *
     * @param list<int|string> $path
     */
    public function addBind(string $property, ?Fiber $owner = null, array $path = []): string
    {
        $id = ($owner ?? $this->top())->id ?? 'x';
        $key = $id.'.'.$property;
        foreach ($path as $segment) {
            $key .= '['.json_encode($segment, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR).']';
        }
        $this->binds[$key] = [$id, $property, $path];

        return $key;
    }

    /**
     * The fiber id, the property and the path of a key that the last pass binds, or null.
     *
     * @return array{string, string, list<int|string>}|null
     */
    public function bound(string $key): ?array
    {
        return $this->binds[$key] ?? null;
    }

    /**
     * The fiber of a component instance. It can be a fiber of an earlier pass: a fiber that does
     * not render again keeps its instance (for example the owner of markup in a slot).
     */
    public function fiberOf(object $instance): ?Fiber
    {
        foreach ($this->fibers as $fiber) {
            if ($fiber->instance === $instance) {
                return $fiber;
            }
        }

        return null;
    }

    public function fiberById(string $id): ?Fiber
    {
        return $this->fibers[$id] ?? null;
    }

    /**
     * The place of the state attributes in the root element: PageRenderer replaces it after the
     * render, when the state is known. It is random, so the data of a page cannot contain it.
     */
    public function rootToken(): string
    {
        return $this->rootToken ??= 'phem-root-'.bin2hex(random_bytes(16));
    }

    /**
     * The state that the browser sent for a fiber.
     *
     * @return array<string, mixed>|null
     */
    public function restored(string $id): ?array
    {
        return $this->state[$id] ?? null;
    }

    /** @return list<Fiber> the fibers that rendered in the last pass (not the kept ones) */
    public function rendered(): array
    {
        return array_values(array_filter($this->fibers, fn (Fiber $f): bool => $f->pass === $this->pass && !$f->kept));
    }

    /**
     * The fibers of the page after the last pass, from the page down, in their order: fiber id =>
     * [the parent id, the fiber when it rendered in the last pass]. A fiber that did not render
     * (kept, or not under a fiber that rendered) is as in the shape of the last render, with its
     * children.
     *
     * @return array<string, array{0: string|null, 1: Fiber|null}>
     */
    public function page(): array
    {
        $out = [];
        $roots = array_filter($this->rendered(), static fn (Fiber $f): bool => null === $f->parent);
        if ([] === $roots && null !== $root = $this->shape->root()) {
            $this->collect($root, null, $out);
        }
        foreach ($roots as $fiber) {
            $this->collect($fiber->id, null, $out);
        }

        return $out;
    }

    /**
     * The fibers to update after an event: the fibers that rendered in the last pass and whose own
     * HTML is not the HTML of the last render (see TreeShape), with no such ancestor. The fibers
     * around the root element (see Fiber::$holdsRoot) are not in the browser: a change of the page
     * markup inside the root is a change of the root fiber.
     *
     * @return list<Fiber>
     */
    public function changedFibers(): array
    {
        $changed = [];
        foreach ($this->rendered() as $f) {
            // A fiber that the shape does not know (a state from before the shape) compares with
            // the pass before.
            $before = $this->shape->hashOf($f->id) ?? (isset($f->ownHtml[$this->pass - 1]) ? TreeShape::hash($f->ownHtml[$this->pass - 1]) : null);
            if (!$f->holdsRoot && TreeShape::hash($f->ownHtml[$this->pass] ?? '') !== $before) {
                $changed[$f->id] = $f;
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

    /** @param array<string, array{0: string|null, 1: Fiber|null}> $out */
    private function collect(string $id, ?string $parent, array &$out): void
    {
        $fiber = $this->fibers[$id] ?? null;
        if (null !== $fiber && $fiber->pass === $this->pass && !$fiber->kept) {
            $out[$id] = [$parent, $fiber];
            foreach ($fiber->children as $child) {
                $this->collect($child->id, $id, $out);
            }

            return;
        }
        $out[$id] = [$parent, null];
        foreach ($this->shape->childrenOf($id) as $child) {
            $this->collect($child, $id, $out);
        }
    }
}
