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

namespace Mamu\Path;

use Mamu\Binding\ComponentName;

/**
 * A string goes to an `int`, `float` or `bool` prop as a value of that type. The other
 * built-in types get the value as it is.
 */
final class ScalarPathConverter implements PathValueConverter
{
    public function supports(PathProp $prop): bool
    {
        return $prop->type->isBuiltin();
    }

    public function convert(PathProp $prop, mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }
        $type = $prop->type->getName();

        return match ($type) {
            'int' => filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE),
            'bool' => filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE),
            default => $value,
        } ?? throw new \UnexpectedValueException(\sprintf('The route value "%s" of <%s> is not a valid %s: "%s".', $prop->name, ComponentName::short($prop->page), $type, $value));
    }
}
