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

use Phem\Attribute\Component;
use Phem\Attribute\Prop;

/** A replacement of a component with #[Broadcast]: it gets the streams and the keys of the original. */
#[Component(replaces: KeyedBroadcastingComponent::class)]
final class KeyedBroadcastingReplacement
{
    #[Prop] public Scientist $scientist;
    #[Prop] public Size $size = Size::Small;
    /** @var array<string, mixed> */
    #[Prop(rest: true)] public array $attrs = [];
}
