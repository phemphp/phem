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

namespace Phem\Stream;

use Phem\Element;
use Phem\Feed;
use Phem\Manifest\FeedDefinition;
use Phem\Manifest\Manifest;
use Phem\Rendering\Renderer;
use Phem\Rendering\Tree;

/**
 * Turns the changes of the feeds of an event into streams to the page of the event (see
 * Phem\Feed): append() and prepend() add the loop body of the item to the feed element, and
 * replace() and remove() change the element of the item, by its id.
 *
 * Phem\Rendering\EventRenderer calls it after the handler: a handler that throws (also when the
 * commit fails) pushes nothing. The streams wait in Streams, and go to the hub at the end of the
 * request.
 */
final class FeedStreams
{
    public function __construct(
        private readonly Manifest $manifest,
        private readonly Renderer $renderer,
        private readonly Streams $streams,
        private readonly Topics $topics,
    ) {
    }

    /**
     * Queues the changes of the feeds of the components that rendered in the last pass of the tree.
     *
     * @throws \LogicException when a feed changed and the page has no #[Subscribe]
     */
    public function collect(Tree $tree): void
    {
        $topic = null;
        foreach ($tree->rendered() as $fiber) {
            if (null === $component = $fiber->instance) {
                continue;
            }
            foreach ($this->manifest->feeds($fiber->fqcn) as $property => $definition) {
                $feed = self::feedOf($component, $property);
                if (null === $feed || [] === $changes = $feed->drain()) {
                    continue;
                }
                $topic ??= $this->topic($tree, $fiber->fqcn, $property);
                foreach ($changes as [$action, $item]) {
                    $this->streams->add($topic, $this->stream($definition, $action, $item));
                }
            }
        }
    }

    private function topic(Tree $tree, string $fqcn, string $property): string
    {
        if (!$this->topics->subscribes($tree->page)) {
            throw new \LogicException(\sprintf('The feed "%s::$%s" changed, but the page "%s" has no #[Subscribe]: add it, so the open pages get the change.', $fqcn, $property, $tree->page));
        }

        return $this->streams->to($tree->page, $tree->values)->topic;
    }

    private function stream(FeedDefinition $definition, string $action, mixed $item): Stream
    {
        $html = $this->renderer->detached(new Element($definition->template, [$definition->var => $item]));

        return match ($action) {
            Feed::APPEND, Feed::PREPEND => new Stream($action, $definition->target, $html),
            Feed::REPLACE => new Stream($action, self::idOf($html, $definition), $html, true),
            Feed::REMOVE => new Stream($action, self::idOf($html, $definition)),
            default => throw new \LogicException(\sprintf('Unknown feed action "%s".', $action)),
        };
    }

    /** The id of the root element of an item (see the `phem_feed_item()` Twig function). */
    private static function idOf(string $html, FeedDefinition $definition): string
    {
        if (1 !== preg_match('/^\s*<[a-zA-Z][\w:-]*[^>]*?\sid="([^"]*)"/', $html, $m)) {
            throw new \LogicException(\sprintf('The item of the feed element "%s" has no id. Run `phemc build` again.', $definition->target));
        }

        return html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private static function feedOf(object $component, string $property): ?Feed
    {
        if (!property_exists($component, $property)) {
            return null;
        }
        $reflection = new \ReflectionProperty($component, $property);
        $feed = $reflection->isInitialized($component) ? $reflection->getValue($component) : null;

        return $feed instanceof Feed ? $feed : null;
    }
}
