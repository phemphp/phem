<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Attribute;

/**
 * Marks a public property of a class page, or a parameter of a function page, as a route
 * value: `{id}` in `#[Route('/scientists/{id}')]` goes to the `#[Path] $id` property or
 * parameter. The page gets the value as a prop, also in the events of the page.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Path
{
}
