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
use Phem\Stream\BroadcastKeys;

/**
 * Gives a new instance of a class component its feeds (see Phem\Feed): a new feed for each feed
 * property (bind(), before mount()), and then the items that the browser sent for the fiber (see
 * FeedItem and load(), after the state of the instance).
 *
 * The browser sends the signed item (see FeedItemToken). The binder checks it with the scope of
 * the instance, the class of the component and the name of the feed, and loads the item again
 * (see EntityFinder):
 *
 * - The item of the event renders in the feed, so the handlers of the item are found. Its scope is
 *   the page, its route values and the fiber id: an item of another instance (a message of another
 *   room) is not valid.
 * - The item of a push goes in the feed with its change (`append`, `prepend` or `update`). Its
 *   scope is the broadcast key of the push: the instance must have this key (see BroadcastKeys).
 *
 * An item that is not in the database any more, or that is not valid for the instance, does not
 * render: the event does not find its handler, and the page loads again (409), as for a row that
 * another user removed.
 *
 * @internal
 */
final class FeedBinder
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ?FeedItemToken $tokens = null,
        private readonly ?EntityFinder $finder = null,
        private readonly BroadcastKeys $keys = new BroadcastKeys(),
    ) {
    }

    /** Sets a new feed on each feed property of a new instance, before its `mount()`. */
    public function bind(object $component): void
    {
        foreach ($this->metadata->feedProperties($component::class) as $property) {
            $property->setValue($component, new Feed($property->getName()));
        }
    }

    /**
     * Gives the feeds of a new instance the items that the browser sent, after its state: the
     * broadcast keys of the instance read its props and its state.
     */
    public function load(object $component, Fiber $fiber): void
    {
        if ([] === $fiber->feedItems || null === $this->tokens) {
            return;
        }

        $feeds = [];
        foreach ($this->metadata->feedProperties($component::class) as $property) {
            $feeds[$property->getName()] = $property->getValue($component);
        }

        // The keys of the instance: a pushed item is valid for one of them.
        $pushScopes = null;
        foreach ($fiber->feedItems as $item) {
            $feed = $feeds[$item->feed] ?? null;
            if (!$feed instanceof Feed) {
                continue;
            }

            if (null !== $item->operation) {
                $pushScopes ??= array_map(FeedItemToken::pushScope(...), $this->keys->ofFiber($fiber));
            }

            $verified = $this->verify($item, $fiber->fqcn, null !== $item->operation ? $pushScopes : [$fiber->feedScope]);
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

    /**
     * @param list<string> $scopes
     *
     * @return array{0: class-string, 1: array<string, mixed>}|null
     */
    private function verify(FeedItem $item, string $component, array $scopes): ?array
    {
        foreach ($scopes as $scope) {
            if (null !== $verified = $this->tokens?->verify($item->token, $scope, $component, $item->feed)) {
                return $verified;
            }
        }

        return null;
    }
}
