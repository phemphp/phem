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

/** A context of the /context page: an enum is a key, a string is not. */
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
}
