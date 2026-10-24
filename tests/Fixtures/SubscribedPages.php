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

use Phem\Attribute\Subscribe;
use Symfony\Component\HttpFoundation\Request;

// A function page with #[Subscribe], for the topics (see Phem\Stream\Topics). Its parameters
// get a route value, the Request and a service (plain PHP, not compiled by phemc).

#[Subscribe]
function SubscribedPage(int $id, string $tab, Request $request, Guestbook $guestbook): void
{
}

function NotSubscribedPage(int $id): void
{
}
