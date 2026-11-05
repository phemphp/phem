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

namespace Phem\Attribute;

/**
 * A prop or a property that identifies an instance of a component with #[Broadcast] (see
 * Phem\Stream\BroadcastKeys): the refresh of the component goes only to the instances with the
 * same values of its key props. An entity is its identifier, an enum is its value.
 *
 *     #[Component, Broadcast]
 *     final class LikeButton
 *     {
 *         #[Prop, BroadcastKey] public Post $post;
 *         #[Prop] public string $size = 'md';
 *     }
 *
 *     $this->streams->toComponent(LikeButton::class, ['post' => $post])->refresh();
 *
 * Mark all the props that identify the data of the component, and no prop that only changes
 * its look: a page that renders the same post with another `size` must get the refresh too. A
 * function component marks its parameters:
 *
 *     #[Component, Broadcast]
 *     function LikeCount(#[BroadcastKey] Post $post): Node { ... }
 *
 * A class component can also mark a property that is not a prop, also a private one that
 * mount() sets (for example from the current user): Phem reads the keys after the render. The
 * key must have a value after each render: in a component with #[State], mount() runs only at
 * the first render, so the key is a #[Prop] or a #[State].
 *
 *     #[Component, Broadcast]
 *     final class OrderList
 *     {
 *         #[BroadcastKey] private int $customer;
 *
 *         public function mount(): void { $this->customer = $this->customers->get()->id; ... }
 *     }
 *
 *     $this->streams->toComponent(OrderList::class, ['customer' => $customer->id])->refresh();
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class BroadcastKey
{
}
