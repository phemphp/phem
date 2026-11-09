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

use Phem\Stream\BroadcastKeys;
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
    /**
     * The fiber whose render() gave the props and the slots, as the owner of a React element: the
     * parent, or for a fiber in a slot, the owner of the fiber of the slot (the slot renders in
     * the context of the caller). Null for a fiber in the template of the page.
     */
    public ?Fiber $owner = null;

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
     * The fiber did not render in this pass (see RenderScope): the browser keeps its HTML, and
     * its parent has a placeholder for it.
     */
    public bool $kept = false;
    /**
     * The fiber renders the `<Root>` of the page in this pass (the page, the document): it is
     * around the root element, so it has no fiber id and the events do not update it.
     */
    public bool $holdsRoot = false;
    /** The fiber or an ancestor is stale (see Tree::markStale()): it renders in this pass. */
    public bool $refreshed = false;
    /**
     * The contexts that the fiber read in this pass: type => the id of the provider fiber (see
     * ContextResolver). A fiber that read a context is a consumer: TreeShape marks it.
     *
     * @var array<class-string, string>
     */
    public array $contexts = [];
    /**
     * The contexts that the fiber provided in the pass `$providedPass`: type => value.
     *
     * @var array<class-string, mixed>
     */
    public array $provided = [];
    public int $providedPass = 0;
    /** The fiber provides a context that changed in this pass: its consumers render (see UpdateScope). */
    public bool $changesContext = false;
    /** The fiber rendered in the second pass of an event only because a context above it changed. */
    public bool $propagated = false;
    /**
     * The keys of a component with #[Broadcast] (see Phem\Stream\BroadcastKeys): an attribute
     * next to its fiber id, for a refresh with no target.
     *
     * @var list<string>
     */
    public array $broadcast = [];
    /** The HTML of the last render. */
    public string $html = '';
    /**
     * The end of the attributes of the root element of `$html`, or null when the HTML is not one
     * element (see RootTag): the fiber id goes on this element.
     */
    public ?int $rootTag = null;
    /**
     * The HTML without the child fibers' content, by pass.
     *
     * @var array<int, string>
     */
    public array $ownHtml = [];
    /**
     * The feed elements of the last render, by id (see Phem\Feed and FeedContent).
     *
     * @var array<string, FeedContent>
     */
    public array $feeds = [];
    /**
     * The items of a feed that the browser sent for this fiber (see FeedBinder).
     *
     * @var list<FeedItem>
     */
    public array $feedItems = [];

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
        $this->kept = false;
        $this->rootTag = null;
        $this->holdsRoot = false;
        $this->contexts = [];
        $this->changesContext = false;
        $this->children = [];
        $this->childIndexes = [];
        $this->feeds = [];
    }

    public function nextIndex(string $fqcn): int
    {
        $this->childIndexes[$fqcn] ??= 0;

        return $this->childIndexes[$fqcn]++;
    }

    /**
     * Sets the HTML of a render, and finds its root element: in `$own`, the HTML with a placeholder
     * for each child, when it is given (see RootTag::find()).
     */
    public function rendered(string $html, ?string $own = null): void
    {
        $root = RootTag::find($html, $own);
        [$this->html, $this->rootTag] = null !== $root ? $root : [$html, null];
    }

    /**
     * The HTML with the fiber id: `data-phem-c` on the root element, or a `<phem-c>` around the
     * HTML when it is a text, more than one element or another fiber.
     */
    public function wrapped(): string
    {
        if ($this->kept) {
            // The phem controller keeps the element of the browser for this placeholder. A
            // `<template>` is valid in each parent, also in a `<table>` and a `<select>`.
            return '<template data-phem-keep="'.$this->id.'"></template>';
        }

        $attributes = ' data-phem-c="'.$this->id.'"'.([] !== $this->broadcast ? ' '.BroadcastKeys::ATTRIBUTE.'="'.implode(' ', $this->broadcast).'"' : '');

        if (null !== $this->rootTag) {
            return substr_replace($this->html, $attributes, $this->rootTag, 0);
        }

        return '<phem-c'.$attributes.'>'.$this->html.'</phem-c>';
    }

    /** What the parent gave the fiber in its last pass: its props and its slots. */
    public function inputs(): FiberInputs
    {
        return new FiberInputs($this->props, $this->slots);
    }

    /** The HTML in the parent: wrapped(), or the HTML alone around the root of the page. */
    public function output(): string
    {
        return $this->holdsRoot && !$this->kept ? $this->html : $this->wrapped();
    }
}
