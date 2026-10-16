<?php

declare(strict_types=1);

namespace Rephp\Rendering;

/**
 * The fibers of one page for one request.
 *
 * The server keeps no state between requests. The browser sends the state of all fibers
 * (signed); a Tree starts from it. The Renderer renders the tree, runs an event handler,
 * renders it again, and the tree reports which fibers changed.
 */
final class Tree
{
    /** @var array<string, Fiber> */
    public array $fibers = [];
    public int $pass = 0;
    public int $rootIndex = 0;
    /** The URL that the event handler redirects to (it returned a RedirectResponse). */
    public ?string $redirect = null;

    /** @var list<Fiber> */
    private array $stack = [];
    /** @var array<string, \Closure> */
    private array $handlers = [];

    /**
     * @param string                              $page  the page component, or '' for a tree without a page
     * @param array<string, array<string, mixed>> $state fiber id => state that the browser sent back
     * @param array<string, mixed>                $props the props of the page: its #[Path] route values
     */
    public function __construct(
        public readonly string $page = '',
        private readonly array $state = [],
        public readonly array $props = [],
    ) {
    }

    /** Starts a render pass. */
    public function begin(): void
    {
        ++$this->pass;
        $this->rootIndex = 0;
        $this->handlers = [];
    }

    /** @return array<string, mixed>|null the state that the browser sent back for a fiber */
    public function restored(string $id): ?array
    {
        return $this->state[$id] ?? null;
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

    /** Registers an event handler of the current fiber. Its id is stable between requests. */
    public function addHandler(\Closure $handler): string
    {
        $fiber = $this->top();
        $id = null === $fiber ? 'x.'.\count($this->handlers) : $fiber->id.'.'.$fiber->handlerIndex++;
        $this->handlers[$id] = $handler;

        return $id;
    }

    public function handler(string $id): ?\Closure
    {
        return $this->handlers[$id] ?? null;
    }

    /** @return list<Fiber> the fibers of the last pass */
    public function mounted(): array
    {
        return array_values(array_filter($this->fibers, fn (Fiber $f): bool => $f->pass === $this->pass));
    }

    /**
     * The fibers to update after a second pass: the changed fibers whose ancestors did not change.
     *
     * @return list<Fiber>
     */
    public function changedFibers(): array
    {
        $changed = [];
        foreach ($this->mounted() as $f) {
            if (($f->ownHtml[$this->pass] ?? null) !== ($f->ownHtml[$this->pass - 1] ?? null)) {
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
