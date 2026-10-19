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

/** A backed enum for a bound field (see the `bind` fixture page). */
enum Size: string
{
    case Small = 's';
    case Large = 'l';
}
