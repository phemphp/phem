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
 * Marks a property of a class component, or a parameter of a function component, as a context:
 * its value comes from the nearest component above it that provides its type (see Provide), and
 * not from the parent in the markup.
 *
 *     #[Component]
 *     function Tab(string $name, #[Context] Tabs $tabs): Node { ... }
 *
 * The declared type is the key: one class, interface or enum, nullable or not. A context with no
 * default value and a type that does not accept null is required: the render throws when no
 * component above provides it.
 *
 * A #[Context] property is not static, not readonly, not promoted, and not a prop or state.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Context
{
}
