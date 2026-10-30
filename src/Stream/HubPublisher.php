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

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes to a Mercure hub with its HTTP API: a POST with a publisher token, for a process
 * with no FrankenPHP request (a request of PHP-FPM, a Messenger worker, a command, see
 * SapiPublisher).
 *
 * The token is a JWT (HS256, `typ: at+jwt`) of the modern Mercure protocol: it grants the
 * `publish` action on all the topics, for one minute. The hub needs the publisher key in the
 * issuer of Phem: `issuer phem { publisher { jwt <key> } }` in the Caddyfile.
 */
final class HubPublisher implements StreamPublisher
{
    private const DETAIL = 'https://mercure.rocks/authorization-detail';

    /**
     * @param string $url    the URL of the hub for the server (`http://localhost:8080/.well-known/mercure`)
     * @param string $key    the publisher key of the hub
     * @param string $issuer the issuer of the hub configuration
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $url,
        private readonly string $key,
        private readonly string $issuer = 'phem',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * As FrankenPhpPublisher: an error does not fail the work that published, the logger gets it.
     */
    public function publish(array $topics, string $data, bool $private): void
    {
        $body = implode('&', [
            ...array_map(static fn (string $topic): string => 'topic='.rawurlencode($topic), $topics),
            'data='.rawurlencode($data),
            ...($private ? ['private=on'] : []),
        ]);

        try {
            $this->client->request('POST', $this->url, [
                'headers' => ['Authorization' => 'Bearer '.$this->token(), 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => $body,
            ])->getContent();
        } catch (\Throwable $e) {
            $this->logger?->error('Phem could not publish the streams to {topics} with the hub at {url}: {message}', ['topics' => implode(', ', $topics), 'url' => $this->url, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function token(): string
    {
        $header = self::encode(['alg' => 'HS256', 'typ' => 'at+jwt']);
        $claims = self::encode([
            'iss' => $this->issuer,
            'aud' => $this->url,
            'sub' => 'phem',
            'client_id' => 'phem',
            'iat' => time(),
            'exp' => time() + 60,
            'jti' => bin2hex(random_bytes(16)),
            'authorization_details' => [[
                'type' => self::DETAIL,
                'actions' => ['publish'],
                'topics' => [['match' => '*']],
            ]],
        ]);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$claims, $this->key, true)), '+/', '-_'), '=');

        return $header.'.'.$claims.'.'.$signature;
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }
}
