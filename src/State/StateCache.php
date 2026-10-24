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

namespace Phem\State;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Keeps each state that the server signs, by its signature, so an event can send only the
 * signature and not the full state.
 *
 * The cache is only an optimization: the browser keeps the full signed state, and it sends it
 * again when the server does not find it. So the pool can lose an entry at any time (an expired
 * entry, another machine, a restart), and its lifetime can be shorter than `phem.state_max_age`.
 * The cache does not make a state trusted: give the JSON to StateCodec::decode() with its
 * signature, as a JSON from the browser.
 *
 * A small state does not go in the pool: the browser sends it with each event (see
 * inlineSize()). So the pool keeps only the states that make a request large, and a page view
 * with a small state writes nothing.
 *
 * The pool is `cache.phem.state` (see PhemBundle). With no pool (`phem.state_cache: false`), the
 * cache keeps nothing, and the browser sends each state.
 */
final class StateCache
{
    /** The id of the cache pool (framework.cache.pools). */
    public const POOL = 'cache.phem.state';

    /** The form of a signature (see StateCodec): the time, a dot, and the HMAC in hex. */
    private const SIGNATURE = '/^\d{1,20}\.[0-9a-f]{64}$/D';

    /**
     * @param int $minSize the `phem.state_cache_min_size` option: the bytes of the smallest state
     *                     that the pool keeps
     */
    public function __construct(
        private readonly ?CacheItemPoolInterface $pool = null,
        private readonly int $minSize = 4096,
    ) {
    }

    /** @param string $json the signed JSON of a state, and its signature from StateCodec::encode() */
    public function save(string $signature, string $json): void
    {
        $key = $this->key($signature);
        if (null === $key || null === $this->pool || \strlen($json) < $this->minSize) {
            return;
        }

        $this->pool->save($this->pool->getItem($key)->set($json));
    }

    /** @return string|null the JSON of the state, or null when the cache does not have it */
    public function find(string $signature): ?string
    {
        $key = $this->key($signature);
        if (null === $key || null === $this->pool) {
            return null;
        }

        $json = $this->pool->getItem($key)->get();

        return \is_string($json) ? $json : null;
    }

    /**
     * The size under which the browser sends the state with each event, because the pool does not
     * keep it. Null with no pool: the browser always sends the state.
     */
    public function inlineSize(): ?int
    {
        return null !== $this->pool ? $this->minSize : null;
    }

    /**
     * The key of a signature: a hash, so the key has only the characters that PSR-6 accepts.
     * Null for a value that is not a signature: it comes from the browser.
     */
    private function key(string $signature): ?string
    {
        return 1 === preg_match(self::SIGNATURE, $signature) ? hash('xxh128', $signature) : null;
    }
}
