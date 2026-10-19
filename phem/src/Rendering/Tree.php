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

/**
 * The fibers of one page for one request.
 *
 * The server keeps no state between requests. The browser sends the state of all fibers
 * (signed); a Tree starts from it. The Renderer renders the tree, runs an event handler,
 * renders it again, and the tree reports which fibers changed.
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
     */
    public function __construct(
        public readonly string $page = '',
        private readonly array $state = [],
        public readonly array $props = [],
        public readonly array $values = [],
        public readonly array $query = [],
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

    /** The fiber of a component instance in the last pass. */
    public function fiberOf(object $instance): ?Fiber
    {
        foreach ($this->fibers as $fiber) {
            if ($fiber->instance === $instance && $fiber->pass === $this->pass) {
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

    /** The fiber of the page: the first fiber of the last pass at the root of the tree. */
    public function root(): ?Fiber
    {
        foreach ($this->mounted() as $f) {
            if (null === $f->parent) {
                return $f;
            }
        }

        return null;
    }

    /** @return list<Fiber> the fibers of the last pass */
    public function mounted(): array
    {
        return array_values(array_filter($this->fibers, fn (Fiber $f): bool => $f->pass === $this->pass));
    }

    /**
     * The fibers to update after a second pass: the changed fibers whose ancestors did not change.
     * The fibers around the root element (see Fiber::$holdsRoot) are not in the browser: a change
     * of the page markup inside the root is a change of the root fiber.
     *
     * @return list<Fiber>
     */
    public function changedFibers(): array
    {
        $changed = [];
        foreach ($this->mounted() as $f) {
            if (!$f->holdsRoot && ($f->ownHtml[$this->pass] ?? null) !== ($f->ownHtml[$this->pass - 1] ?? null)) {
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
}
