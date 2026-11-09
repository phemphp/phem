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

use Phem\Feed;

/**
 * The render of a feed element in one pass of its fiber (see Phem\Twig\PhemRuntime::feed()): the
 * HTML of each item that rendered.
 *
 * The own HTML of the fiber has a marker in place of the items (see marker()), so its hash does not
 * change with the items (see Phem\State\TreeShape). The HTML for the browser has the items in
 * place of the marker when the browser does not have the feed element yet (see Renderer); otherwise
 * the items go in their own Turbo Streams (see Phem\Http\FeedStreams).
 *
 * @internal
 */
final class FeedContent
{
    /** The items are in the HTML of the fiber: the browser had no feed element. */
    public bool $inline = false;

    /** @var array<int, string> the HTML of each item that rendered, by object id */
    private array $html = [];

    /**
     * @param string $id        the id of the feed element
     * @param Feed   $feed      the feed of the component
     * @param string $component the class of the component (see FeedItemToken)
     */
    public function __construct(
        public readonly string $id,
        public readonly Feed $feed,
        public readonly string $component,
    ) {
    }

    public function add(object $item, string $html): void
    {
        $this->html[spl_object_id($item)] = $html;
    }

    /** The HTML of an item, or null when it did not render (a deleted item). */
    public function htmlOf(object $item): ?string
    {
        return $this->html[spl_object_id($item)] ?? null;
    }

    /**
     * The HTML of a list of items, in their order.
     *
     * @param list<object> $items
     */
    public function htmlOfAll(array $items): string
    {
        return implode('', array_map(fn (object $item): string => $this->htmlOf($item) ?? '', $items));
    }

    /** The marker of the items in the HTML of the fiber. */
    public function marker(): string
    {
        return '<!--phem-feed:'.$this->id.'-->';
    }

    /** Puts the items in place of the marker (see $inline), or removes the marker. */
    public function fill(string $html): string
    {
        return str_replace($this->marker(), $this->inline ? $this->htmlOfAll($this->feed->ordered()) : '', $html);
    }
}
