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
 * React 19 style: a `<title>` anywhere in a page goes into `<head>`. The `<title>` of an
 * `<svg>` is the name of the image, so it stays.
 */
final class Title
{
    private const PATTERN = '#<title>(.*?)</title>#s';

    /**
     * @return array{0: string|null, 1: string} the title (null without one), and the HTML without the title
     */
    public static function extract(string $html): array
    {
        $m = self::find($html);
        if (null === $m) {
            return [null, $html];
        }

        return [trim($m[1][0]), substr_replace($html, '', $m[0][1], \strlen($m[0][0]))];
    }

    /** The HTML without its first `<title>`. */
    public static function strip(string $html): string
    {
        return self::extract($html)[1];
    }

    /**
     * The first `<title>` that is not in an `<svg>`.
     *
     * @return array{0: array{string, int}, 1: array{string, int}}|null the tag and the text, with their offsets
     */
    private static function find(string $html): ?array
    {
        preg_match_all(self::PATTERN, $html, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $before = substr($html, 0, $m[0][1]);
            if (preg_match_all('#<svg[\s>/]#i', $before) <= preg_match_all('#</svg\s*>#i', $before)) {
                return [$m[0], $m[1]];
            }
        }

        return null;
    }
}
