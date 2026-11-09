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
use Phem\Metadata\ComponentMetadata;
use Phem\Path\EntityFinder;

/**
 * Gives a new instance of a class component its feeds (see Phem\Feed): a new feed for each feed
 * property, with the items that the browser sent for the fiber (see FeedItem).
 *
 * The browser sends the signed item (see FeedItemToken). The binder checks it with the class of
 * the component and the name of the feed, and loads the item again (see EntityFinder):
 *
 * - The item of the event renders in the feed, so the handlers of the item are found. An item that
 *   is not in the database any more, or that is not valid for the feed (another feed, a changed
 *   token), does not render: the event does not find its handler, and the page loads again (409),
 *   as for a row that another user removed.
 * - The item of a push goes in the feed with its change (`append`, `prepend` or `update`).
 *
 * @internal
 */
final class FeedBinder
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ?FeedItemToken $tokens = null,
        private readonly ?EntityFinder $finder = null,
    ) {
    }

    public function bind(object $component, Fiber $fiber): void
    {
        $feeds = [];
        foreach ($this->metadata->feedProperties($component::class) as $property) {
            $feed = new Feed($property->getName());
            $property->setValue($component, $feed);
            $feeds[$feed->name] = $feed;
        }

        foreach ($fiber->feedItems as $item) {
            $feed = $feeds[$item->feed] ?? null;
            $verified = null !== $feed ? $this->tokens?->verify($item->token, $fiber->fqcn, $item->feed) : null;
            if (null === $verified) {
                continue;
            }

            [$class, $identifier] = $verified;
            $entity = true === $this->finder?->supports($class) ? $this->finder->find($class, $identifier) : null;
            if (null === $entity) {
                continue;
            }

            match ($item->operation) {
                null => $feed->load($entity),
                'append' => $feed->append($entity),
                'prepend' => $feed->prepend($entity),
                'update' => $feed->update($entity),
            };
        }
    }
}
