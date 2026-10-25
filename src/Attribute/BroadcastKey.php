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
 * A prop that identifies an instance of a component with #[Broadcast] (see
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
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class BroadcastKey
{
}
