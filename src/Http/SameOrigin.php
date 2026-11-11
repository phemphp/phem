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

namespace Phem\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether the browser sent a request from a page of the same origin (against CSRF, see
 * Phem\Controller\EventController and Phem\Upload\UploadController). Without the
 * `Sec-Fetch-Site` and `Origin` headers, the client is not a browser, and a CSRF attack
 * needs one.
 */
final class SameOrigin
{
    public static function check(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site) {
            return 'same-origin' === $site;
        }

        $origin = $request->headers->get('Origin');
        if (null === $origin) {
            return true;
        }

        $url = parse_url($origin);
        $host = ($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');

        return '' !== $host && 0 === strcasecmp($host, $request->getHttpHost());
    }
}
