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

namespace Phem\Dom;

/**
 * The scheme of a URL, as the browser parses it: it removes the control characters and the
 * spaces at the ends, and the tabs and line breaks, so `" Java\tScript:alert(1)"` is a
 * `javascript:` URL.
 */
final class UrlScheme
{
    /** The scheme in lowercase (`https`, `javascript`), or null for a relative URL. */
    public static function of(string $url): ?string
    {
        $normalized = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));

        return 1 === preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $m) ? strtolower($m[1]) : null;
    }
}
