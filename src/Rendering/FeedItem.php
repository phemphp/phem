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

/**
 * An item of a feed that the browser sends with an event (see FeedBinder): the item of the element
 * that sent the event, or an item that a push gives to the feed (see Phem\Stream\FeedPush).
 *
 * @internal
 */
final class FeedItem
{
    /** The id of a feed element: the fiber id and the name of the feed property. */
    public const FEED_ID = '/\A(phem[0-9a-f]{12})-([A-Za-z_][A-Za-z0-9_]*)\z/';

    /**
     * @param string                           $fiber     the fiber of the component of the feed
     * @param string                           $feed      the name of the feed property
     * @param string                           $token     the signed item (see FeedItemToken)
     * @param 'append'|'prepend'|'update'|null $operation the change of a push, or null for the item of the event
     */
    public function __construct(
        public readonly string $fiber,
        public readonly string $feed,
        public readonly string $token,
        public readonly ?string $operation = null,
    ) {
    }

    /**
     * The item of the id of a feed element (`phem0123456789ab-messages`), or null for another id.
     *
     * @param 'append'|'prepend'|'update'|null $operation
     */
    public static function of(string $feedId, string $token, ?string $operation = null): ?self
    {
        if (1 !== preg_match(self::FEED_ID, $feedId, $m)) {
            return null;
        }

        return new self($m[1], $m[2], $token, $operation);
    }
}
