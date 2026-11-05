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

namespace Phem\Test\Internal;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cookies of one browser: all the pages of the browser share them, as the tabs of a browser
 * do (see Phem\Test\ComponentTester::visit()). As a browser, it sends a cookie only to its host
 * (or to its `Domain` and its subdomains) and to its `Path`, before it expires. It sends a
 * `Secure` cookie only on HTTPS and on `localhost`.
 *
 * @internal
 */
final class CookieJar
{
    /** The hosts where a browser sends a `Secure` cookie on plain HTTP too. */
    private const SECURE_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @var array<string, array{cookie: Cookie, host: string, hostOnly: bool}> by name, host and path */
    private array $cookies = [];

    /** Keeps the cookies of the response, and removes the cookies that it clears. */
    public function keep(Request $request, Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            // With no Domain, only the host of the request gets the cookie.
            $domain = $cookie->getDomain();
            $host = null !== $domain ? strtolower(ltrim($domain, '.')) : $request->getHost();
            $key = $cookie->getName().';'.$host.';'.$cookie->getPath();

            if ($cookie->isCleared() || null === $cookie->getValue()) {
                unset($this->cookies[$key]);
                continue;
            }

            $this->cookies[$key] = ['cookie' => $cookie, 'host' => $host, 'hostOnly' => null === $domain];
        }
    }

    /**
     * Keeps cookies that the test gives, as a server sets them for a URL: on the host of the URL
     * only, for all its paths, until the end of the browser. As in a browser, a `__Secure-` or a
     * `__Host-` cookie is `Secure`: it goes on HTTPS and on `localhost` only.
     *
     * @param array<array-key, mixed> $cookies name => value
     *
     * @throws \InvalidArgumentException for a value that is not a string, or a name that a cookie cannot have
     */
    public function add(string $url, array $cookies): void
    {
        if ([] === $cookies) {
            return;
        }

        $host = Request::create($url)->getHost();

        foreach ($cookies as $name => $value) {
            $name = (string) $name;
            if (!\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('The value of the cookie "%s" is "%s": give a string.', $name, get_debug_type($value)));
            }

            $secure = str_starts_with($name, '__Secure-') || str_starts_with($name, '__Host-');

            try {
                $cookie = Cookie::create($name, $value, 0, '/', null, $secure);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(\sprintf('A cookie cannot have the name "%s": %s', $name, $e->getMessage()), 0, $e);
            }

            $this->cookies[$name.';'.$host.';/'] = ['cookie' => $cookie, 'host' => $host, 'hostOnly' => true];
        }
    }

    /**
     * The cookies that the browser sends to a URL: name => value. With two cookies of the same
     * name, the cookie with the longer path wins, as the browser sends it first.
     *
     * @return array<string, string>
     */
    public function for(string $url): array
    {
        $request = Request::create($url);
        $host = $request->getHost();
        $path = $request->getPathInfo();
        $secure = $request->isSecure() || \in_array($host, self::SECURE_HOSTS, true);

        $matches = array_filter($this->cookies, static fn (array $entry): bool => self::hostMatches($entry, $host)
            && self::pathMatches($entry['cookie']->getPath(), $path)
            && ($secure || !$entry['cookie']->isSecure())
            && !self::expired($entry['cookie']));

        usort($matches, static fn (array $a, array $b): int => \strlen($b['cookie']->getPath()) <=> \strlen($a['cookie']->getPath()));

        $cookies = [];
        foreach ($matches as $entry) {
            $cookies[$entry['cookie']->getName()] ??= (string) $entry['cookie']->getValue();
        }

        return $cookies;
    }

    /** @param array{cookie: Cookie, host: string, hostOnly: bool} $entry */
    private static function hostMatches(array $entry, string $host): bool
    {
        return $host === $entry['host'] || (!$entry['hostOnly'] && str_ends_with($host, '.'.$entry['host']));
    }

    /** The path of the URL is the path of the cookie, or a path below it (RFC 6265, 5.1.4). */
    private static function pathMatches(string $cookie, string $path): bool
    {
        if ($cookie === $path || '/' === $cookie) {
            return true;
        }

        return str_starts_with($path, $cookie) && (str_ends_with($cookie, '/') || '/' === $path[\strlen($cookie)]);
    }

    /** A cookie with a date expires at that date. A session cookie (no date) stays. */
    private static function expired(Cookie $cookie): bool
    {
        return 0 !== $cookie->getExpiresTime() && $cookie->getExpiresTime() < time();
    }
}
