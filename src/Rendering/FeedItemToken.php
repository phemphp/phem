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

use Phem\Stream\NameHash;

/**
 * The signed identity of an item of a feed, in the `data-phem-item` attribute of its element: the
 * class and the identifier of the item (see FeedIdentity), and an HMAC of them with the component
 * and the name of the feed. An event from inside the item sends it back, and the server loads the
 * item again (see FeedBinder). The browser cannot ask for an item that it did not get, or for an
 * item of another feed.
 *
 * The token is not bound to a fiber or a page: a push gives the same item to all the pages that
 * show the feed (see Phem\Stream\FeedPush).
 *
 * @internal
 */
final class FeedItemToken
{
    /** @param string $secret the key of the HMAC (`phem.secret`) */
    public function __construct(
        private readonly string $secret,
    ) {
    }

    /**
     * @param string               $component  the class of the component of the feed
     * @param class-string         $class      the class of the item
     * @param array<string, mixed> $identifier the identifier of the item
     */
    public function sign(string $component, string $feed, string $class, array $identifier): string
    {
        $payload = self::base64(json_encode([$class, $identifier], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->mac($component, $feed, $payload);
    }

    /**
     * The class and the identifier of the item, or null when the token is not valid for the feed.
     *
     * @return array{0: class-string, 1: array<string, mixed>}|null
     */
    public function verify(string $token, string $component, string $feed): ?array
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts) || !hash_equals($this->mac($component, $feed, $parts[0]), $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = false !== $json ? json_decode($json, true, 4) : null;
        if (!\is_array($data) || !\is_string($data[0] ?? null) || !\is_array($data[1] ?? null) || !class_exists($data[0])) {
            return null;
        }

        return [$data[0], $data[1]];
    }

    private function mac(string $component, string $feed, string $payload): string
    {
        return NameHash::of($this->secret, "phem-feed\n".$component."\n".$feed."\n".$payload);
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
