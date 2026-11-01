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

namespace Phem\Binding;

/**
 * A prop with a backed enum type: a string or an int value becomes a case, so
 * `<Button variant="outline">` gives `Variant::Outline`. A case and null stay as they are.
 */
final class EnumConverter implements PropConverter
{
    public function convert(?\ReflectionType $type, mixed $value, string $component, string $prop): mixed
    {
        if (null === $value || \is_array($value) || \is_object($value) || !$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $enum = $type->getName();
        if (!is_subclass_of($enum, \BackedEnum::class)) {
            return $value;
        }

        $case = \is_string($value) || \is_int($value) ? EnumCase::from($enum, $value) : null;
        if (null === $case) {
            $values = array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases());
            $given = \is_string($value) ? '"'.$value.'"' : var_export($value, true);

            throw new PropError(\sprintf('<%s> has no %s %s. Its values are: %s.', ComponentName::short($component), $prop, $given, implode(', ', $values)));
        }

        return $case;
    }
}
