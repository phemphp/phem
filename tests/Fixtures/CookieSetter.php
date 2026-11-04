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

namespace Phem\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Sets the cookie of the query of a request on its response, for the cookies of the browser of
 * the tests (see cookies.phpm): `?set=theme=dark`, with an optional `path`, `domain` and `secure`.
 * `?clear=theme` clears the cookie.
 */
final class CookieSetter
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $query = $event->getRequest()->query;
        $headers = $event->getResponse()->headers;

        if ($query->has('set')) {
            [$name, $value] = explode('=', $query->getString('set'), 2) + [1 => ''];
            $domain = $query->has('domain') ? $query->getString('domain') : null;
            $headers->setCookie(Cookie::create($name, $value, 0, $query->getString('path', '/'), $domain, $query->getBoolean('secure'), true, false, null));
        }

        if ($query->has('clear')) {
            $headers->clearCookie($query->getString('clear'));
        }
    }
}
