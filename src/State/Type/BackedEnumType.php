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

/** A backed enum is its value in the JSON of the state: it becomes a case again. */
final class BackedEnumType implements StateType
{
    public function supports(\ReflectionNamedType $type): bool
    {
        return is_subclass_of($type->getName(), \BackedEnum::class);
    }

    public function toState(mixed $value, string $component, string $property): mixed
    {
        return $value;
    }

    public function fromState(\ReflectionNamedType $type, mixed $state): mixed
    {
        $enum = $type->getName();

        return (\is_int($state) || \is_string($state)) && is_subclass_of($enum, \BackedEnum::class) ? $enum::from($state) : $state;
    }
}
