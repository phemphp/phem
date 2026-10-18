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
    /** The fiber of the `<Root>` of the last pass (see Mamu\Root). */
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
    private ?string $rootToken = null;

    /**
     * @param string                              $page   the page component, or '' for a tree without a page
     * @param array<string, array<string, mixed>> $state  fiber id => state that the browser sent back
     * @param array<string, mixed>                $props  the props of the page: its #[Path] route values (an entity for an entity prop)
     * @param array<string, mixed>                $values the route values of the #[Path] props, as the route gives them (an identifier or criteria for an entity prop)
     */
    public function __construct(
        public readonly string $page = '',
        private readonly array $state = [],
        public readonly array $props = [],
        public readonly array $values = [],
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

    public function handler(string $id): ?\Closure
    {
        return $this->handlers[$id] ?? null;
    }

    /**
     * The place of the state attributes in the root element: PageRenderer replaces it after the
     * render, when the state is known. It is random, so the data of a page cannot contain it.
     */
    public function rootToken(): string
    {
        return $this->rootToken ??= 'mamu-root-'.bin2hex(random_bytes(16));
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
