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

namespace Phem\Upload;

/**
 * The signed reference of a file that the browser uploaded before its event: a chunked upload
 * (see ChunkStore) or a direct upload (see DirectUploader). The event sends the token, not the
 * file. The browser cannot change a token, and a token expires after MAX_AGE seconds.
 *
 * A token is `<base64url JSON>.<base64url HMAC>`. The JSON has the data and the expiry time.
 */
final class UploadToken
{
    /** The seconds that a token lives: the event must come in this time. */
    public const MAX_AGE = 86400;

    /** @param string $secret the key of the HMAC (`phem.secret`) */
    public function __construct(private readonly string $secret)
    {
    }

    /** @param array<string, scalar> $data */
    public function sign(array $data): string
    {
        $payload = self::encode(json_encode([...$data, 'exp' => time() + self::MAX_AGE], \JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->hmac($payload);
    }

    /**
     * The data of a token, or null when the token is not valid or expired.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts) || !hash_equals($this->hmac($parts[0]), $parts[1])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!\is_array($data) || !\is_int($data['exp'] ?? null) || $data['exp'] < time()) {
            return null;
        }

        unset($data['exp']);

        return $data;
    }

    private function hmac(string $payload): string
    {
        return self::encode(hash_hmac('sha256', 'upload'."\n".$payload, $this->secret, true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
