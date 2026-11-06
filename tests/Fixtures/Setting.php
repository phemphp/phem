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

/** A row that a handler changes in place, as a Doctrine entity (see slots.phem). */
final class Setting
{
    public static ?self $current = null;

    public function __construct(public int $days = 1)
    {
    }

    public static function current(): self
    {
        return self::$current ??= new self();
    }
}
