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

use Phem\Test\ComponentTester;

/**
 * One browser of a test: its cookies, and its pages (see Phem\Test\InteractsWithPhem::visit()).
 * As the tabs of a browser, its pages share the cookies. A page opens a relative URL from its own
 * URL, as a link does.
 *
 * @internal
 */
final class Browser
{
    /**
     * @param \Closure(self, string): ComponentTester $load loads a URL as a page of the browser
     */
    public function __construct(
        private readonly \Closure $load,
        private readonly CookieJar $cookies = new CookieJar(),
    ) {
    }

    /**
     * Opens a URL in a new tab. The cookies go to the browser first, for the host of the URL.
     *
     * @param string                $from    the URL of the page that opens it, or "" for the first page
     * @param array<string, string> $cookies name => value
     */
    public function open(string $from, string $uri, array $cookies = []): ComponentTester
    {
        $url = self::resolve($from, $uri);
        $this->cookies->add($url, $cookies);

        return ($this->load)($this, $url);
    }

    /**
     * The cookies that the browser sends to a URL, by name.
     *
     * @return array<string, string>
     */
    public function cookies(string $url): array
    {
        $cookies = $this->cookies->for($url);
        ksort($cookies, \SORT_STRING);

        return $cookies;
    }

    /** The cookies of the browser, for the requests of its pages. */
    public function jar(): CookieJar
    {
        return $this->cookies;
    }

    /**
     * A URL as a link of a page opens it: an absolute URL as it is, and a relative URL on the
     * scheme, the host and the path of the page. A page with a relative URL has the host of
     * Request::create(): the URL stays relative.
     */
    private static function resolve(string $from, string $uri): string
    {
        if ('' === $from || 1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri)) {
            return $uri;
        }

        $base = parse_url($from);
        $base = false !== $base ? $base : [];
        $origin = isset($base['scheme'], $base['host']) ? $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '') : '';
        $path = $base['path'] ?? '/';
        $query = isset($base['query']) ? '?'.$base['query'] : '';

        return match (true) {
            str_starts_with($uri, '//') => (isset($base['scheme']) ? $base['scheme'].':' : '').$uri,
            str_starts_with($uri, '/') => $origin.$uri,
            '' === $uri, str_starts_with($uri, '#') => $origin.$path.$query,
            str_starts_with($uri, '?') => $origin.$path.$uri,
            default => $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$uri,
        };
    }
}
