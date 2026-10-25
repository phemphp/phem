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

use Phem\Attribute\Broadcast;
use Phem\Attribute\BroadcastKey;
use Phem\Attribute\Component;
use Phem\Attribute\Prop;

/** A component with #[Broadcast] and #[BroadcastKey] props, for the keys of its instances (see Phem\Stream\BroadcastKeys). */
#[Component, Broadcast]
final class KeyedBroadcastingComponent
{
    #[Prop, BroadcastKey] public Scientist $scientist;
    #[Prop, BroadcastKey] public Size $size = Size::Small;
    #[Prop] public string $label = '';
}
