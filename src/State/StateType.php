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
 * Keeps the values of the #[State] properties of a type in the JSON of the state (see
 * StateAccessor): a backed enum, a StateValue, or an object of a class. A property with another
 * type keeps its value as it is.
 */
interface StateType
{
    /** Whether the #[State] properties of this type keep their values with it. */
    public function supports(\ReflectionNamedType $type): bool;

    /**
     * The value of a property in the JSON of the state: a JSON value, or a value that
     * json_encode() writes (a backed enum).
     *
     * @param class-string $component the class of the component, for the errors
     */
    public function toState(mixed $value, string $component, string $property): mixed;

    /** The value of a property of this type for a value of toState() from the browser. */
    public function fromState(\ReflectionNamedType $type, mixed $state): mixed;
}
