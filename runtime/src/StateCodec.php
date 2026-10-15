<?php

declare(strict_types=1);

namespace Rephp;

/**
 * Signs the state that the browser keeps, so a client cannot forge it.
 */
final class StateCodec
{
    public function __construct(private readonly string $secret)
    {
    }

    /**
     * Uses RHP_SECRET, or a random secret stored in the cache directory (it survives restarts).
     */
    public static function fromEnvironment(string $cacheDir): self
    {
        $secret = getenv('RHP_SECRET') ?: null;
        if (null === $secret) {
            $file = \dirname($cacheDir).'/secret';
            if (!is_file($file)) {
                @mkdir(\dirname($file), 0o777, true);
                file_put_contents($file, bin2hex(random_bytes(32)));
                chmod($file, 0o600);
            }
            $secret = trim((string) file_get_contents($file));
        }

        return new self($secret);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{0: string, 1: string} the JSON and its signature
     */
    public function encode(array $state, string $route): array
    {
        $json = json_encode((object) $state, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        return [$json, $this->sign($json, $route)];
    }

    /** @return array<string, mixed>|null null when the signature is not valid */
    public function decode(string $json, string $signature, string $route): ?array
    {
        if (!hash_equals($this->sign($json, $route), $signature)) {
            return null;
        }
        $state = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($state) ? $state : null;
    }

    private function sign(string $json, string $route): string
    {
        return hash_hmac('sha256', $route."\n".$json, $this->secret);
    }
}
