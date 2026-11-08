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

namespace Phem\State\Type;

use Phem\State\StateType;
use Phem\State\StateValue;

/** A StateValue is its toState() in the JSON of the state, and its fromState() makes it again. */
final class StateValueType implements StateType
{
    public function supports(\ReflectionNamedType $type): bool
    {
        return is_subclass_of($type->getName(), StateValue::class);
    }

    public function toState(mixed $value, string $component, string $property): mixed
    {
        return $value instanceof StateValue ? $value->toState() : $value;
    }

    public function fromState(\ReflectionNamedType $type, mixed $state): mixed
    {
        $class = $type->getName();

        return is_subclass_of($class, StateValue::class) ? $class::fromState($state) : $state;
    }
}
