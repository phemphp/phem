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

use Mamu\Attribute\Prop;
use Mamu\Html;

/** The parent class of a fixture component, with its slots, outside the .phpm sources. */
abstract class Framed
{
    #[Prop] public ?Html $children = null;
    #[Prop] public ?Html $footer = null;
}
