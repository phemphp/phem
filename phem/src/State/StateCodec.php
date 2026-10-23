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

use Symfony\Component\HttpFoundation\Request;

/**
 * Signs the state that the browser keeps, so a client cannot forge it. A signature is valid
 * only for its page component, and only for a time (the maximum age).
 *
 * A signature does not make a state trusted: the browser can send back any state that the
 * server signed for the page before (an older state, or the state of another visitor). Keep
 * limits and security decisions in the database or the session, not in #[State]. With
 * `bind_session`, a signature is valid only for the session that it was made for.
 *
 * The signature is `<issued at>.<HMAC>`. The HMAC covers the page, the time, the session (with
 * `bind_session`) and the JSON.
 */
final class StateCodec
{
    /**
     * @param string                 $secret      the `phem.secret` option (by default `kernel.secret`, from APP_SECRET)
     * @param int                    $maxAge      the `phem.state_max_age` option: the seconds that a signature is valid, 0 for no limit
     * @param bool                   $bindSession the `phem.state_bind_session` option
     * @param (\Closure(): int)|null $clock       the time now (tests)
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $maxAge = 86400,
        private readonly bool $bindSession = false,
        private readonly ?\Closure $clock = null,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('Phem needs a secret to sign the browser state: set APP_SECRET (framework.secret) or the phem.secret option.');
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param Request|null         $request the request, for `bind_session`
     *
     * @return array{0: string, 1: string} the JSON and its signature
     */
    public function encode(array $state, string $page, ?Request $request = null): array
    {
        $json = StateJson::encode($state);
        $issuedAt = $this->now();

        return [$json, $issuedAt.'.'.$this->sign($json, $page, $issuedAt, $request)];
    }

    /** @return array<string, mixed>|null null when the signature is not valid, or too old */
    public function decode(string $json, string $signature, string $page, ?Request $request = null): ?array
    {
        [$issuedAt, $mac] = explode('.', $signature, 2) + [1 => ''];
        if (!ctype_digit($issuedAt) || !hash_equals($this->sign($json, $page, (int) $issuedAt, $request), $mac)) {
            return null;
        }

        if ($this->maxAge > 0 && $this->now() - (int) $issuedAt > $this->maxAge) {
            return null;
        }

        $state = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($state) ? $state : null;
    }

    /**
     * Whether a signature is older than the maximum age. The time is in the signature, so the
     * server can know it before it has the JSON. A value that is not a signature is not too old:
     * decode() rejects it.
     */
    public function isTooOld(string $signature): bool
    {
        $issuedAt = explode('.', $signature, 2)[0];

        return $this->maxAge > 0 && ctype_digit($issuedAt) && $this->now() - (int) $issuedAt > $this->maxAge;
    }

    private function sign(string $json, string $page, int $issuedAt, ?Request $request): string
    {
        return hash_hmac('sha256', $page."\n".$issuedAt."\n".$this->session($request)."\n".$json, $this->secret);
    }

    /**
     * The session id with `bind_session`: the id of the session that the request started, or
     * else the id in the session cookie. It does not start a session.
     */
    private function session(?Request $request): string
    {
        if (!$this->bindSession || null === $request || !$request->hasSession()) {
            return '';
        }

        $session = $request->getSession();

        return $session->isStarted() ? $session->getId() : (string) $request->cookies->get($session->getName(), '');
    }

    private function now(): int
    {
        return null !== $this->clock ? ($this->clock)() : time();
    }
}
