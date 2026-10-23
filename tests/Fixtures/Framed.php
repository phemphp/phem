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

use Phem\Attribute\Prop;
use Phem\Html;

/** The parent class of a fixture component, with its slots, outside the .phpm sources. */
abstract class Framed
{
    #[Prop] public ?Html $children = null;
    #[Prop] public ?Html $footer = null;
}
