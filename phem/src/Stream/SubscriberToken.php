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
 * The access token of a Mercure subscriber: a JWT (HS256, `typ: at+jwt`) that grants the
 * `subscribe` action on a list of topics, in the `authorization_details` claim of the modern
 * Mercure protocol. The hub checks the issuer (`iss`), the audience (`aud`, the URL of the hub)
 * and the expiry (`exp`), and it closes a subscription when its token expires.
 *
 * Each topic has its own expiry (a grant, see Subscription), in the `phem_exp` claim: the hub
 * ignores it. The token expires with its first topic.
 */
final class SubscriberToken
{
    private const DETAIL = 'https://mercure.rocks/authorization-detail';

    /**
     * @param string $key    the subscriber key of the hub (`issuer … { subscriber { jwt <key> } }` in the Caddyfile)
     * @param string $issuer the issuer of the hub configuration
     * @param int    $ttl    the seconds that a token is valid
     */
    public function __construct(
        private readonly string $key,
        private readonly string $issuer = 'phem',
        private readonly int $ttl = 86400,
    ) {
    }

    /**
     * Each topic expires in the time of a token from now: the topics of a page that passed its
     * access rules.
     *
     * @param list<string> $topics
     *
     * @return array<string, int> topic => expiry (a Unix time)
     */
    public function grant(array $topics): array
    {
        return array_fill_keys($topics, time() + $this->ttl);
    }

    /**
     * @param array<string, int> $grants   topic => expiry (see grant()): the token expires with its first topic
     * @param string             $audience the URL of the hub, as the browser connects to it
     * @param string             $subject  who the token is for (see Subscription): a token of another subject gives no topics
     */
    public function create(array $grants, string $audience, string $subject = 'phem'): string
    {
        $topics = array_map(strval(...), array_keys($grants));
        $header = self::encode(['alg' => 'HS256', 'typ' => 'at+jwt']);
        $claims = self::encode([
            'iss' => $this->issuer,
            'aud' => $audience,
            'sub' => $subject,
            'client_id' => 'phem',
            'iat' => time(),
            'exp' => [] !== $grants ? min($grants) : time() + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
            'authorization_details' => [[
                'type' => self::DETAIL,
                'actions' => ['subscribe'],
                'topics' => array_map(static fn (string $topic): array => ['match' => $topic], $topics),
            ]],
            'phem_exp' => array_values($grants),
        ]);

        return $header.'.'.$claims.'.'.$this->sign($header.'.'.$claims);
    }

    /**
     * The topics of a token of this key, issuer, audience and subject that did not expire, or none.
     *
     * @return list<string>
     */
    public function topics(string $token, string $audience, string $subject = 'phem'): array
    {
        return array_map(strval(...), array_keys($this->grants($token, $audience, $subject)));
    }

    /**
     * The topics of a valid token (see topics()) with their expiry. A topic with no expiry of
     * its own expires with the token.
     *
     * @return array<string, int> topic => expiry (a Unix time)
     */
    public function grants(string $token, string $audience, string $subject = 'phem'): array
    {
        return $this->claims($token, $audience, $subject)['grants'] ?? [];
    }

    /** Whether a valid token lives for more than half of its time: it does not need a renewal. */
    public function isFresh(string $token, string $audience, string $subject = 'phem'): bool
    {
        $claims = $this->claims($token, $audience, $subject);

        return null !== $claims && $this->isFreshExpiry($claims['exp']);
    }

    /** Whether an expiry is more than half of the time of a token from now. */
    public function isFreshExpiry(int $expiry): bool
    {
        return $expiry - time() > intdiv($this->ttl, 2);
    }

    /** @return array{grants: array<string, int>, exp: int}|null */
    private function claims(string $token, string $audience, string $subject): ?array
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts) || !hash_equals($this->sign($parts[0].'.'.$parts[1]), $parts[2])) {
            return null;
        }
        $claims = json_decode(self::decode($parts[1]), true);
        if (!\is_array($claims) || ($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $audience || ($claims['sub'] ?? null) !== $subject || !\is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) {
            return null;
        }
        $expiries = \is_array($claims['phem_exp'] ?? null) ? array_values($claims['phem_exp']) : [];
        $grants = [];
        $i = 0;
        foreach ((array) ($claims['authorization_details'] ?? []) as $detail) {
            foreach (\is_array($detail) ? (array) ($detail['topics'] ?? []) : [] as $topic) {
                if (\is_array($topic) && \is_string($topic['match'] ?? null)) {
                    $expiry = $expiries[$i] ?? null;
                    $grants[$topic['match']] = \is_int($expiry) ? $expiry : $claims['exp'];
                }
                ++$i;
            }
        }

        return ['grants' => $grants, 'exp' => $claims['exp']];
    }

    private function sign(string $data): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $this->key, true)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    private static function decode(string $part): string
    {
        return (string) base64_decode(strtr($part, '-_', '+/'), true);
    }
}
