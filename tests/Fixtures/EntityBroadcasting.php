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
use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Tests\Fixtures\Shop\ShopOrder;
use Phem\Tests\Fixtures\Shop\ShopOrderLine;
use Phem\Tests\Fixtures\Shop\ShopTag;

// The pages and the components with #[Broadcast(on: ...)], for the entity broadcasts (see
// Phem\Stream\EntityRefreshes). Plain PHP, not compiled by phemc.

/** The URL of the order of the #[Path], also for a change of one of its lines. */
#[Broadcast(on: [ShopOrder::class, ShopOrderLine::class])]
final class ShopOrderPage
{
    #[Path] public ShopOrder $order;
}

/** A page with no entity prop: all its URLs. */
#[Broadcast(on: ShopOrder::class)]
final class ShopOrdersPage
{
    #[Path] public int $page = 1;
}

/** A page with an entity and another #[Path] prop: the topic of the entity prop alone. */
#[Broadcast(on: ShopOrderLine::class)]
final class ShopTwoPathPage
{
    #[Path] public int $shop;
    #[Path] public ShopOrder $order;
}

/** All the instances, for a change of the status or of the tags of an order, or of any line. */
#[Component, Broadcast(on: [ShopOrder::class => ['status', 'tags'], ShopOrderLine::class])]
final class ShopAlerts
{
}

/** The instances of the changed line. */
#[Component, Broadcast(on: ShopOrderLine::class)]
final class ShopLineItem
{
    #[Prop, BroadcastKey] public ShopOrderLine $line;
}

/** A component with an entity and another key prop: the key of the entity prop alone. */
#[Component, Broadcast(on: ShopOrder::class, handlers: false)]
final class ShopOrderBadge
{
    #[Prop, BroadcastKey] public ShopOrder $order;
    #[Prop, BroadcastKey] public string $size = 'md';
}

/** A class of `on` that is not an entity (see Phem\Doctrine\EntityBroadcastListener). */
#[Component, Broadcast(on: \ArrayObject::class)]
final class BroadcastingComponentOfAnObject
{
}

/** A field of `on` that the entity does not have. */
#[Component, Broadcast(on: [ShopOrder::class => ['colour']])]
final class BroadcastingComponentOfAField
{
}

/** The inverse side of an association in the fields of `on`: a flush never changes it. */
#[Component, Broadcast(on: [ShopOrder::class => ['lines']])]
final class BroadcastingComponentOfAnInverseSide
{
}

/** A page of an entity whose identifier has an entity (a derived identity). */
#[Broadcast]
final class ShopLinePage
{
    #[Path] public ShopOrderLine $line;
}

/** A function page with an entity parameter that is not a route value. */
#[Broadcast(on: ShopTag::class)]
function ShopOrderFunctionPage(ShopOrder $order, ShopTag $tag): void
{
}
