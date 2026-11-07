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

namespace Phem\Tests\Fixtures\ErrorPageFunctions;

use Phem\Attribute\ErrorPage;

// Error pages for the tests of Phem\Http\ErrorPages (plain PHP, not compiled by phemc).

#[ErrorPage]
function Everything(): void
{
}

#[ErrorPage(404)]
function NotFound(): void
{
}

#[ErrorPage([401, 403])]
function Denied(): void
{
}

#[ErrorPage(path: '/admin')]
function Admin(): void
{
}

#[ErrorPage(404, path: '/admin/')]
function AdminNotFound(): void
{
}

#[ErrorPage(path: '/shop')]
#[ErrorPage(path: '/blog')]
function Sections(): void
{
}

#[ErrorPage(302)]
function Redirect(): void
{
}

#[ErrorPage(path: 'admin')]
function Relative(): void
{
}

#[ErrorPage([404, 410])]
function AlsoNotFound(): void
{
}

// A typo in a named argument: PHP throws an \Error when it makes the attribute.
#[ErrorPage(paths: '/admin')] // @phpstan-ignore argument.unknown
function Typo(): void
{
}
