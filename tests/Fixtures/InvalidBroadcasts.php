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

/** A key on a #[State], not on a prop. */
#[Component, Broadcast]
final class KeyOnState
{
    #[State, BroadcastKey] public int $id = 0;
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

/** A function component with a key parameter, and a key parameter with a default value. */
#[Component, Broadcast]
function KeyedWidget(#[BroadcastKey] int $id, string $label = '', #[BroadcastKey] string $locale = 'en'): void
{
}
