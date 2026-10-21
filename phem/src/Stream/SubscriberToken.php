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
     * @param list<string> $topics
     * @param string       $audience the URL of the hub, as the browser connects to it
     * @param string       $subject  who the token is for (see Subscription): a token of another subject gives no topics
     */
    public function create(array $topics, string $audience, string $subject = 'phem'): string
    {
        $now = time();
        $header = self::encode(['alg' => 'HS256', 'typ' => 'at+jwt']);
        $claims = self::encode([
            'iss' => $this->issuer,
            'aud' => $audience,
            'sub' => $subject,
            'client_id' => 'phem',
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
            'authorization_details' => [[
                'type' => self::DETAIL,
                'actions' => ['subscribe'],
                'topics' => array_map(static fn (string $topic): array => ['match' => $topic], $topics),
            ]],
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
        return $this->claims($token, $audience, $subject)['topics'] ?? [];
    }

    /** Whether a valid token lives for more than half of its time: it does not need a renewal. */
    public function isFresh(string $token, string $audience, string $subject = 'phem'): bool
    {
        $claims = $this->claims($token, $audience, $subject);

        return null !== $claims && $claims['exp'] - time() > intdiv($this->ttl, 2);
    }

    /** @return array{topics: list<string>, exp: int}|null */
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
        $topics = [];
        foreach ((array) ($claims['authorization_details'] ?? []) as $detail) {
            foreach (\is_array($detail) ? (array) ($detail['topics'] ?? []) : [] as $topic) {
                if (\is_array($topic) && \is_string($topic['match'] ?? null)) {
                    $topics[] = $topic['match'];
                }
            }
        }

        return ['topics' => $topics, 'exp' => $claims['exp']];
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
