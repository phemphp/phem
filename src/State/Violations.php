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

use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The fields and the messages of the validation errors, as the Validation of a component keeps
 * them (see Phem\Validation): the bound fields and the mapped forms use the same ones.
 *
 * @internal
 */
final class Violations
{
    /** The error of a value of the browser that does not fit the type of its property. */
    private const REJECTED = 'This value is not valid.';

    /** The field of a property path of the Symfony Validator: `items[0][name]` → `items.0.name`. */
    public static function field(string $path): string
    {
        return ltrim((string) preg_replace('/\[([^\]]*)\]/', '.$1', $path), '.');
    }

    /** The error of a rejected value, translated as the other errors when there is a validator. */
    public static function rejected(?ValidatorInterface $validator): string
    {
        if (null === $validator) {
            return self::REJECTED;
        }

        return (string) $validator->validate(null, new NotNull(message: self::REJECTED))->get(0)->getMessage();
    }
}
