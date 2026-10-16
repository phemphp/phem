<?php

declare(strict_types=1);

namespace Rephp\State;

/**
 * Signs the state that the browser keeps, so a client cannot forge it. A signature is valid
 * only for its page component.
 */
final class StateCodec
{
    /**
     * @param string $secret the `rephp.secret` option (by default `kernel.secret`, from APP_SECRET)
     */
    public function __construct(private readonly string $secret)
    {
        if ('' === $secret) {
            throw new \InvalidArgumentException('rephp needs a secret to sign the browser state: set APP_SECRET (framework.secret) or the rephp.secret option.');
        }
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{0: string, 1: string} the JSON and its signature
     */
    public function encode(array $state, string $page): array
    {
        $json = json_encode((object) $state, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        return [$json, $this->sign($json, $page)];
    }

    /** @return array<string, mixed>|null null when the signature is not valid */
    public function decode(string $json, string $signature, string $page): ?array
    {
        if (!hash_equals($this->sign($json, $page), $signature)) {
            return null;
        }
        $state = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($state) ? $state : null;
    }

    private function sign(string $json, string $page): string
    {
        return hash_hmac('sha256', $page."\n".$json, $this->secret);
    }
}
