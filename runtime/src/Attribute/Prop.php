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
 * Marks a property of a class component as a prop: the parent gives its value in the markup
 * (`<Counter label="Likes" />`). The property must be public, not static, not promoted, and
 * not #[State]. A #[Path] property is a prop too, with no #[Prop].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Prop
{
}
