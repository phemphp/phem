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
 * The HMAC of a name of a topic or a key (see Topics and BroadcastKeys): 128 bits, as 22 base64url
 * characters. The browser, the access token and the hub do not see the name.
 */
final class NameHash
{
    /** @param string $secret the key of the HMAC (`phem.secret`) */
    public static function of(string $secret, string $name): string
    {
        return rtrim(strtr(base64_encode(substr(hash_hmac('sha256', $name, $secret, true), 0, 16)), '+/', '-_'), '=');
    }
}
