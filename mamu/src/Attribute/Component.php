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
 * Marks a class or a function as a component. A class must have a `render()` method; its
 * #[Prop] properties are the props. The parameters of a function are its props. A function
 * without this attribute is a plain PHP function, also in a .phpm file.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_FUNCTION)]
final class Component
{
}
