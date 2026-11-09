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

use Phem\Handler\ObjectIdentifier;

/**
 * The identity of an item of a feed (see Phem\Feed): its class and its identifier (see
 * ObjectIdentifier), and the id of its element in the browser. The id is the id of the feed
 * element and the identifier values: `phem0123456789ab-messages-41`.
 *
 * @internal
 */
final class FeedIdentity
{
    public function __construct(
        private readonly ?ObjectIdentifier $objects = null,
    ) {
    }

    /**
     * The class and the identifier of an item. A value of the identifier is a scalar, or a
     * Stringable object (a UUID) as its string.
     *
     * @return array{0: class-string, 1: array<string, int|float|string|bool>}
     *
     * @throws \LogicException when the item has no identifier, or a value of its identifier is another object (an association)
     */
    public function of(string $feed, object $item): array
    {
        $identifier = $this->objects?->identify($item);
        if (null === $identifier || [] === $identifier) {
            throw new \LogicException(\sprintf('The item of the feed "%s" is a %s object, but Phem cannot identify it. An item of a feed is an entity: Phem loads it again with its identifier for an event from inside the item.', $feed, $item::class));
        }

        $values = [];
        foreach ($identifier as $field => $value) {
            $values[$field] = match (true) {
                \is_scalar($value) => $value,
                $value instanceof \Stringable => (string) $value,
                default => throw new \LogicException(\sprintf('The item of the feed "%s" is a %s, and the field "%s" of its identifier is a %s. Phem identifies an item by scalar values: give the entity an identifier of its own, not an association.', $feed, $item::class, $field, get_debug_type($value))),
            };
        }

        return [$item::class, $values];
    }

    /**
     * The key of an item in the id of its element: the value of its identifier, or a hash of a
     * composite identifier or of a value with other characters than a CSS identifier has.
     */
    public function key(string $feed, object $item): string
    {
        $values = array_values($this->of($feed, $item)[1]);
        $key = 1 === \count($values) ? (string) $values[0] : '';

        if (1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $key)) {
            $key = substr(hash('xxh128', json_encode($values, \JSON_THROW_ON_ERROR)), 0, 16);
        }

        return $key;
    }

    /** The id of the element of an item: the id of the feed element and the key of the item. */
    public function elementId(string $feedId, string $feed, object $item): string
    {
        return $feedId.'-'.$this->key($feed, $item);
    }
}
