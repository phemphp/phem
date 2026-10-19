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

    public int $pass = 0;
    /**
     * The fiber renders the `<Root>` of the page in this pass (the page, the document): it is
     * around the root element, so it has no `<phem-c>` and the events do not update it.
     */
    public bool $holdsRoot = false;
    /** The inner HTML of the last render. */
    public string $html = '';
    /**
     * The HTML without the child fibers' content, by pass.
     *
     * @var array<int, string>
     */
    public array $ownHtml = [];

    /**
     * @param array<string, mixed>|null $restored the state that the browser sent back, or null for a new fiber
     * @param array<array-key, mixed>   $query    the query string of the page request, for the #[Url] properties of a new fiber
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $fqcn,
        public readonly ?array $restored = null,
        public readonly array $query = [],
    ) {
    }

    public static function idOf(string $path): string
    {
        return 'phem'.substr(hash('xxh128', $path), 0, 12);
    }

    public function begin(int $pass): void
    {
        $this->pass = $pass;
        $this->holdsRoot = false;
        $this->children = [];
        $this->childIndexes = [];
    }

    public function nextIndex(string $fqcn): int
    {
        $this->childIndexes[$fqcn] ??= 0;

        return $this->childIndexes[$fqcn]++;
    }

    public function wrapped(): string
    {
        return '<phem-c id="'.$this->id.'">'.$this->html.'</phem-c>';
    }

    /** The HTML in the parent: wrapped(), or the HTML alone around the root of the page. */
    public function output(): string
    {
        return $this->holdsRoot ? $this->html : $this->wrapped();
    }
}
