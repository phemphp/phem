<?php

declare(strict_types=1);

namespace Rephp\Rendering;

use Twig\Template;

/**
 * One component instance in the render tree. It keeps the class instance (a service from the
 * container) and the HTML of the last render passes.
 *
 * The id comes from the position in the tree (parent, component, index or key), so it is the
 * same in each request: the browser keeps the state by fiber id.
 *
 * @internal
 */
final class Fiber
{
    public ?Fiber $parent = null;

    /** @var array<string, mixed> */
    public array $props = [];
    public ?Template $slots = null;
    /** @var array<string, mixed> */
    public array $caller = [];

    /** The component object of a class component. It lives for the whole request. */
    public ?object $instance = null;

    /** @var list<Fiber> */
    public array $children = [];
    /** @var array<string, int> */
    private array $childIndexes = [];
    public int $handlerIndex = 0;

    public int $pass = 0;
    /** The inner HTML of the last render. */
    public string $html = '';
    /** The HTML without the child fibers' content, by pass. @var array<int, string> */
    public array $ownHtml = [];

    /**
     * @param array<string, mixed>|null $restored the state that the browser sent back, or null for a new fiber
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $fqcn,
        public readonly string $kind,
        public readonly ?array $restored = null,
    ) {
    }

    public static function idOf(string $path): string
    {
        return 'rh'.substr(hash('xxh128', $path), 0, 12);
    }

    public function begin(int $pass): void
    {
        $this->pass = $pass;
        $this->handlerIndex = 0;
        $this->children = [];
        $this->childIndexes = [];
    }

    public function nextIndex(string $fqcn): int
    {
        $this->childIndexes[$fqcn] ??= 0;

        return $this->childIndexes[$fqcn]++;
    }

    public function name(): string
    {
        return substr(strrchr('\\'.$this->fqcn, '\\'), 1);
    }

    public function wrapped(): string
    {
        return '<rh-c id="'.$this->id.'">'.$this->html.'</rh-c>';
    }
}
