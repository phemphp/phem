<?php

declare(strict_types=1);

namespace Mamu\Binding;

/**
 * The name of a component in the error messages: `App\Pages\Counter` → `Counter`.
 */
final class ComponentName
{
    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }
}
