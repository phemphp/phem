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

/**
 * The topics of the connection of a page to the hub: the `match` values of its URL (the
 * `data-phem-stream-value` of the root element), as the hub reads them.
 *
 * @internal
 */
final class StreamTopics
{
    /** @return list<string> */
    public static function of(string $url): array
    {
        $topics = [];
        foreach (explode('&', (string) parse_url($url, \PHP_URL_QUERY)) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ('match' === $name) {
                $topics[] = rawurldecode($value);
            }
        }

        return $topics;
    }
}
