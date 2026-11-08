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

use Phem\State\StateObjects;
use Phem\State\StateType;

/**
 * An object of a class (a draft, a date) is its value from the Serializer in the JSON of the
 * state (see StateObjects). Null stays null.
 */
final class ObjectType implements StateType
{
    public function __construct(private readonly StateObjects $objects = new StateObjects())
    {
    }

    public function supports(\ReflectionNamedType $type): bool
    {
        return null !== StateObjects::classOf($type);
    }

    public function toState(mixed $value, string $component, string $property): mixed
    {
        return \is_object($value) ? $this->objects->toState($value, $component, $property) : $value;
    }

    public function fromState(\ReflectionNamedType $type, mixed $state): mixed
    {
        $class = StateObjects::classOf($type);

        return null !== $state && null !== $class ? $this->objects->fromState($class, $state) : $state;
    }
}
