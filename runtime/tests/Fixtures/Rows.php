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

namespace Mamu\Tests\Fixtures;

/** Rows that other users change too (as a database table), for the tests of the handler ids. */
final class Rows
{
    /** @var array<int, Scientist> */
    public static array $scientists = [];
    /** @var list<int> */
    public static array $removed = [];

    public static function reset(): void
    {
        self::$scientists = [1 => new Scientist(1, 'Ada'), 2 => new Scientist(2, 'Grace'), 3 => new Scientist(3, 'Hedy'), 4 => new Scientist(4, 'Marie')];
        self::$removed = [];
    }

    public static function remove(int $id): void
    {
        self::$removed[] = $id;
        unset(self::$scientists[$id]);
    }
}
