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

/** Counts the renders of the components of the /skip page, and keeps data outside the state. */
final class Renders
{
    /** @var array<string, int> component => renders */
    public static array $counts = [];
    /** Data outside the state of the page, as a database row. */
    public static int $external = 0;

    public static function reset(): void
    {
        self::$counts = [];
        self::$external = 0;
    }

    public static function count(string $component): void
    {
        self::$counts[$component] = (self::$counts[$component] ?? 0) + 1;
    }

    public static function of(string $component): int
    {
        return self::$counts[$component] ?? 0;
    }
}
