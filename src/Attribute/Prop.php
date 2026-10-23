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
 * Marks a property of a class component as a prop: the parent gives its value in the markup
 * (`<Counter label="Likes" />`). The property must be public, not static, not promoted, and
 * not #[State]. A #[Path] property is a prop too, with no #[Prop].
 *
 * A rest prop (`#[Prop(rest: true)] public array $attrs = [];`) collects the props that no
 * other prop takes, as a hash, like the variadic parameter of a function component. Spread it
 * in the markup: `<button {{ ...attrs }}>`. A class has at most one rest prop, and its type
 * accepts an array.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Prop
{
    public function __construct(
        public readonly bool $rest = false,
    ) {
    }
}
