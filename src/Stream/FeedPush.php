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

/**
 * The changes of a feed in the other browsers (see Phem\Feed and PageStreams::feed()):
 *
 *     $this->streams->toComponent(ChatRoom::class, ['room' => $room])
 *         ->feed('messages')
 *         ->append($message);
 *
 * The push has no markup. It has the signed item, and each browser that shows the feed sends it
 * back with a refresh: its server renders the item in the feed, and the handlers of the item work.
 * Phem does not check if each user may see the item: push it only to the instances whose users
 * may see it (the key of toComponent()), or check access in the markup of the item.
 *
 * A `delete()` removes the element of the item in the browser, with no request. In an event
 * request, the browser that sent the event skips a component that the event rendered: its handler
 * changed the feed already (see SenderToken).
 */
final class FeedPush
{
    /**
     * @internal use PageStreams::feed()
     */
    public function __construct(
        private readonly Streams $streams,
        private readonly string $topic,
        private readonly string $key,
        private readonly string $component,
        private readonly string $name,
    ) {
    }

    /** Adds items at the end of the feed, in their order. */
    public function append(object ...$items): self
    {
        foreach ($items as $item) {
            $this->add('append', $item);
        }

        return $this;
    }

    /** Adds items at the start of the feed, in their order. */
    public function prepend(object ...$items): self
    {
        // Each item goes at the start: the last item goes first, so the items keep their order.
        foreach (array_reverse($items) as $item) {
            $this->add('prepend', $item);
        }

        return $this;
    }

    /** Renders an item of the feed again, in its place. */
    public function update(object $item): self
    {
        return $this->add('update', $item);
    }

    /** Removes an item from the feed. */
    public function delete(object $item): self
    {
        return $this->add('delete', $item);
    }

    private function add(string $operation, object $item): self
    {
        [$key, $token] = $this->streams->feedItem($this->component, $this->name, $item);
        $data = ['phem-feed' => $this->name, 'phem-feed-op' => $operation, 'phem-feed-key' => $key] + ('delete' !== $operation ? ['phem-item' => $token] : []);

        $this->streams->add($this->topic, new TurboStream(TurboStream::FEED, '['.BroadcastKeys::ATTRIBUTE.'~="'.$this->key.'"]', many: true, data: $data));

        return $this;
    }
}
