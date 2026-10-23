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
 * Provides a context to the components below a class component (see Context).
 *
 * On a property, the value of the property is the context, and the declared type of the property
 * is its key. On the class, the component itself is the context: the consumers read its state
 * and call its handlers, as the items of a compound component do:
 *
 *     #[Component, Provide]
 *     final class Tabs { #[State] public string $active = 'general'; ... }
 *
 * The consumers render again when the context changes, also under components that do not render
 * again: when the provider renders, for the component itself or a mutable object, and when the
 * value is another value, for an enum or a readonly object.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS)]
final class Provide
{
}
