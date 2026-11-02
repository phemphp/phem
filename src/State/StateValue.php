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

namespace Phem\State;

/**
 * A value object that a #[State] property can hold (see StateAccessor). The state keeps its
 * JSON value (toState()), and fromState() makes the object again.
 *
 * A property of this type is never null: on a new component, Phem sets an uninitialized or null
 * property to `fromState(null)`.
 *
 * Make it immutable: a change gives a new object. A child that gets the object as a prop renders
 * again only when the object is another one (see Phem\Rendering\InputsComparator).
 */
interface StateValue
{
    /** The JSON value of the object: scalars, null and arrays. */
    public function toState(): mixed;

    /** The object of a JSON value of toState(), or the initial object for null. */
    public static function fromState(mixed $state): static;
}
