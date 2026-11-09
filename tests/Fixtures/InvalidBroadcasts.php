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
use Phem\Attribute\Page;
use Phem\Attribute\Prop;
use Phem\Attribute\State;

// The #[Broadcast] and #[BroadcastKey] that Phem rejects (plain PHP, not compiled by phemc).

/** A key prop of a component with no #[Broadcast]. */
#[Component]
final class KeyWithoutBroadcast
{
    #[Prop, BroadcastKey] public int $id = 0;
}

/** A key on a #[State]: the browser keeps it, so it has a value in each render. */
#[Component, Broadcast]
final class KeyOnState
{
    #[State, BroadcastKey] public int $id = 0;
}

/** A private key that mount() sets in each render: the component has no #[State]. */
#[Component, Broadcast]
final class KeyFromMount
{
    /** @phpstan-ignore property.onlyWritten (Phem reads the key after the render) */
    #[BroadcastKey] private int $customer;

    public function mount(): void
    {
        $this->customer = 7;
    }
}

/** A private key that mount() sets, in a component with #[State]: mount() runs only at the first render. */
#[Component, Broadcast]
final class KeyOnlyInFirstMount
{
    #[State] public bool $open = false;
    /** @phpstan-ignore property.onlyWritten (Phem reads the key after the render) */
    #[BroadcastKey] private int $customer;

    public function mount(): void
    {
        $this->customer = 7;
    }
}

/** A key on a page: a page has no instances on other pages. */
#[Page('/keyed'), Broadcast]
final class KeyOnPage
{
    #[Prop, BroadcastKey] public int $id = 0;
}

/** A handler of a page with #[Broadcast]: only a handler of a class component refreshes it. */
#[Page('/refreshed')]
final class BroadcastHandlerOfAPage
{
    #[Broadcast]
    public function save(): void
    {
    }
}

/** A page with #[Broadcast] and a handler with #[Broadcast]. */
#[Page('/refreshed/too'), Broadcast]
final class BroadcastingPageWithAHandler
{
    #[Broadcast]
    public function save(): void
    {
    }
}

/** A page with #[Broadcast(handlers: false)]: a page has no handlers that refresh it. */
#[Page('/received'), Broadcast(handlers: false)]
final class ReceivingPage
{
}

/** A class with #[Broadcast(handlers: false)] and no #[Component]: it is not a component. */
#[Broadcast(handlers: false)]
final class ReceivingPlainClass
{
}

/** A function component with #[Broadcast(handlers: false)]: a function has no handlers that refresh it. */
#[Component, Broadcast(handlers: false)]
function ReceivingWidget(): void
{
}

/** A component with #[Broadcast(handlers: false)], and a handler with #[Broadcast]. */
#[Component, Broadcast(handlers: false)]
final class ReceivingComponentWithAHandler
{
    #[Broadcast]
    public function save(): void
    {
    }
}

/** A handler with #[Broadcast(handlers: false)]. */
#[Component]
final class HandlerWithHandlersFalse
{
    #[Broadcast(handlers: false)]
    public function save(): void
    {
    }
}

/** A function component with a key parameter, and a key parameter with a default value. */
#[Component, Broadcast]
function KeyedWidget(#[BroadcastKey] int $id, string $label = '', #[BroadcastKey] string $locale = 'en'): void
{
}

/** A handler with entities: only a page or a component has `on`. */
#[Component]
final class EntitiesOnAHandler
{
    #[Broadcast(on: \stdClass::class)]
    public function save(): void
    {
    }
}

/** A class of `on` that does not exist. */
#[Component, Broadcast(on: 'App\NoSuchEntity')]
final class UnknownBroadcastEntity
{
}

/** A field that is not a list. */
#[Component, Broadcast(on: [\stdClass::class => 'status'])]
final class BroadcastFieldsNotAList
{
}
