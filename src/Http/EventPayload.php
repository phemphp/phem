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

use Phem\Rendering\FeedItem;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The JSON of an event (`POST <page URL>`), from the `phem` Stimulus controller (see EventBody).
 * The page comes from the route, not from the JSON.
 */
final class EventPayload
{
    /**
     * The maximum depth of the JSON body, as json_decode() counts it. The body from the browser
     * has a depth of 5 (the body, the event, the form fields, a [name, value] pair, and the
     * value; or the body, the bound values, and a list of strings), so a deeper body does not
     * come from it.
     */
    public const MAX_DEPTH = 8;

    /** The maximum number of stale fibers of an event. */
    public const MAX_STALE = 100;

    /** The maximum number of handlers of a load event (the `<Suspense>` boundaries that load together). */
    public const MAX_LOADS = 100;

    /** The maximum length of the signed item of a feed (see Phem\Rendering\FeedItemToken). */
    public const MAX_ITEM = 1024;

    /** The maximum number of items that pushes gave to the feeds of a page (see Phem\Stream\FeedPush). */
    public const MAX_FEEDS = 100;

    /**
     * @param list<string>         $handlers the handler ids: one, none for an event of `phem:live` or a refresh, or the
     *                                       handlers of the `<Suspense>` boundaries that load together
     * @param string|null          $state    the signed JSON of the page state, or null when the event sends only the signature (see StateCache)
     * @param array<string, mixed> $event    the DOM event (type, value, checked, key, form, touched)
     * @param array<string, mixed> $bound    the values of the bound inputs that changed: "<fiber>.<property>" => value (see BoundValues)
     * @param list<string>         $stale    the fibers whose element a push changed (see Phem\Rendering\Tree::markStale())
     * @param FeedItem|null        $item     the item of a feed around the element of the event (see Phem\Feed)
     * @param list<FeedItem>       $feeds    the items that pushes gave to the feeds of the page (see Phem\Stream\FeedPush)
     */
    public function __construct(
        public readonly array $handlers,
        public readonly ?string $state,
        public readonly string $signature,
        public readonly array $event,
        public readonly array $bound = [],
        public readonly array $stale = [],
        public readonly ?FeedItem $item = null,
        public readonly array $feeds = [],
    ) {
    }

    /**
     * @throws BadRequestHttpException when the body is not JSON, is too deep, or has no handler
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, self::MAX_DEPTH);
        if (!\is_array($data) || !\array_key_exists('handler', $data)) {
            throw new BadRequestHttpException('An event needs a handler.');
        }

        $event = \is_array($data['event'] ?? null) ? $data['event'] : [];

        return new self(
            self::handlers($data['handler'], \is_array($data['bound'] ?? null), $event),
            \is_string($data['state'] ?? null) ? $data['state'] : null,
            (string) ($data['signature'] ?? ''),
            $event,
            \is_array($data['bound'] ?? null) ? $data['bound'] : [],
            self::stale($data['stale'] ?? null),
            self::item($data['item'] ?? null),
            self::feeds($data['feeds'] ?? null),
        );
    }

    /**
     * The items of the event and of the pushes, for the tree (see Phem\Rendering\Tree).
     *
     * @return list<FeedItem>
     */
    public function feedItems(): array
    {
        return null !== $this->item ? [$this->item, ...$this->feeds] : $this->feeds;
    }

    /**
     * The item of a feed of the `item` value: the id of the feed element and the signed item (see
     * Phem\Rendering\FeedItemToken), or null.
     *
     * @throws BadRequestHttpException when the value does not come from the browser
     */
    private static function item(mixed $item, bool $push = false): ?FeedItem
    {
        if (null === $item && !$push) {
            return null;
        }

        $feed = \is_array($item) ? ($item['feed'] ?? null) : null;
        $token = \is_array($item) ? ($item['token'] ?? null) : null;
        $operation = $push && \is_array($item) ? ($item['op'] ?? null) : null;
        if (!\is_string($feed) || !\is_string($token) || \strlen($token) > self::MAX_ITEM || ($push && !\in_array($operation, ['append', 'prepend', 'update'], true))) {
            throw new BadRequestHttpException('An item of a feed has the id of its feed and its signed item, and an item of a push has its change.');
        }

        /* @var 'append'|'prepend'|'update'|null $operation */
        return FeedItem::of($feed, $token, $operation) ?? throw new BadRequestHttpException('An item of a feed has the id of its feed and its signed item.');
    }

    /**
     * The items of the `feeds` list, at most MAX_FEEDS: the items that pushes gave to the feeds of
     * the page, with their change.
     *
     * @return list<FeedItem>
     */
    private static function feeds(mixed $feeds): array
    {
        if (null === $feeds) {
            return [];
        }

        if (!\is_array($feeds) || !array_is_list($feeds) || \count($feeds) > self::MAX_FEEDS) {
            throw new BadRequestHttpException(\sprintf('An event has a list of %d items of feeds at most.', self::MAX_FEEDS));
        }

        return array_map(static fn (mixed $item): FeedItem => self::item($item, true) ?? throw new BadRequestHttpException('An item of a feed is not valid.'), $feeds);
    }

    /**
     * The handler ids of the `handler` value: a string, null for an event with only the bound
     * values (`phem:live`) or a refresh, or a list for the `<Suspense>` boundaries that load
     * together (a `load` event).
     *
     * @param array<string, mixed> $event
     *
     * @return list<string>
     */
    private static function handlers(mixed $handler, bool $bound, array $event): array
    {
        if (\is_string($handler)) {
            return [$handler];
        }

        if (null === $handler && $bound) {
            return [];
        }

        if (!\is_array($handler) || [] === $handler || !array_is_list($handler) || 'load' !== ($event['type'] ?? null)) {
            throw new BadRequestHttpException('An event needs a handler. Only a load event has a list of handlers.');
        }

        if (\count($handler) > self::MAX_LOADS) {
            throw new BadRequestHttpException(\sprintf('A load event has %d handlers at most.', self::MAX_LOADS));
        }

        $ids = [];
        foreach ($handler as $id) {
            if (!\is_string($id)) {
                throw new BadRequestHttpException('The handlers of a load event are strings.');
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The fiber ids of the `stale` list, at most MAX_STALE: a longer list does not come from the
     * browser. A value that is not a fiber id is ignored.
     *
     * @return list<string>
     */
    private static function stale(mixed $stale): array
    {
        if (!\is_array($stale)) {
            return [];
        }

        if (\count($stale) > self::MAX_STALE) {
            throw new BadRequestHttpException(\sprintf('An event has %d stale components at most.', self::MAX_STALE));
        }

        return array_values(array_filter($stale, static fn (mixed $id): bool => \is_string($id) && 1 === preg_match('/\Aphem[0-9a-f]{12}\z/', $id)));
    }
}
