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

/** A function replacement that gives all its props to the original in its rest prop: the keys use the defaults of the original. */
#[Component(replaces: KeyedBroadcastingComponent::class)]
function KeyedBroadcastingFunction(mixed ...$rest): string
{
    return '';
}
