<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Http;

/**
 * React 19 style: a `<title>` anywhere in a page goes into `<head>`.
 */
final class Title
{
    private const PATTERN = '#<title>(.*?)</title>#s';

    /**
     * @return array{0: string|null, 1: string} the title (null without one), and the HTML without the title
     */
    public static function extract(string $html): array
    {
        if (!preg_match(self::PATTERN, $html, $m)) {
            return [null, $html];
        }

        return [trim($m[1]), str_replace($m[0], '', $html)];
    }

    /** The HTML without its first `<title>`. */
    public static function strip(string $html): string
    {
        return preg_replace(self::PATTERN, '', $html, 1) ?? $html;
    }
}
