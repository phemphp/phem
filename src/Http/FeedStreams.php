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

namespace Phem\Http;

use Phem\Rendering\FeedContent;
use Phem\Rendering\FeedIdentity;
use Phem\Rendering\Tree;
use Phem\Stream\TurboStream;

/**
 * The Turbo Streams of the feeds of an event (see Phem\Feed): the changes of each feed element
 * that rendered in the last pass, in their order. The browser keeps the other items: the morph of
 * the component does not change the content of a feed element.
 *
 * - `append()` and `prepend()` give one stream for all their items, so the items keep their order.
 * - `update()` and the item of the event give a `replace` of the item, with a morph.
 * - `delete()` gives a `remove` of the item, and `reset()` an `update` of the feed element.
 *
 * A feed element that the browser does not have yet has its items in the HTML of its component
 * (see FeedContent::$inline), with no stream.
 */
final class FeedStreams
{
    public function __construct(
        private readonly FeedIdentity $identity = new FeedIdentity(),
    ) {
    }

    public function of(Tree $tree): string
    {
        $streams = '';
        foreach ($tree->rendered() as $fiber) {
            foreach ($fiber->feeds as $content) {
                if (!$content->inline) {
                    $streams .= $this->streams($content);
                }
            }
        }

        return $streams;
    }

    private function streams(FeedContent $content): string
    {
        $feed = $content->feed;
        $streams = '';
        $changed = [];

        foreach ($feed->operations() as [$action, $items]) {
            foreach ($items as $item) {
                $changed[spl_object_id($item)] = true;
            }

            if ('append' === $action || 'prepend' === $action || 'reset' === $action) {
                $html = $content->htmlOfAll($items);
                if ('' !== $html || 'reset' === $action) {
                    $streams .= new TurboStream('reset' === $action ? 'update' : $action, $content->id, $html)->toHtml();
                }

                continue;
            }

            foreach ($items as $item) {
                $streams .= $this->ofItem($content, $item, 'delete' === $action);
            }
        }

        // The item of the event renders again: its handler can change it.
        foreach ($feed->loaded() as $item) {
            if (!isset($changed[spl_object_id($item)])) {
                $streams .= $this->ofItem($content, $item, false);
            }
        }

        return $streams;
    }

    private function ofItem(FeedContent $content, object $item, bool $delete): string
    {
        $id = $this->identity->elementId($content->id, $content->feed->name, $item);
        if ($delete) {
            return new TurboStream('remove', $id)->toHtml();
        }

        $html = $content->htmlOf($item);

        return null !== $html ? new TurboStream('replace', $id, $html, morph: true)->toHtml() : '';
    }
}
