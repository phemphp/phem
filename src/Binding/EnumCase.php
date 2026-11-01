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
 * The case of a backed enum for a string or an int, as a prop or a form field gives it. For an
 * int-backed enum, a string is the text of the int (`"3"`, not `"03"` or `"+3"`).
 */
final class EnumCase
{
    /** @var array<class-string<\BackedEnum>, bool> */
    private static array $intBacked = [];

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public static function from(string $enum, int|string $value): ?\BackedEnum
    {
        self::$intBacked[$enum] ??= 'int' === (string) (new \ReflectionEnum($enum))->getBackingType();

        if (!self::$intBacked[$enum]) {
            return $enum::tryFrom((string) $value);
        }

        if (\is_string($value) && (string) (int) $value !== $value) {
            return null;
        }

        return $enum::tryFrom((int) $value);
    }
}
