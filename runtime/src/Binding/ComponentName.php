<?php

declare(strict_types=1);

namespace Rephp\Binding;

/**
 * The name of a component in the error messages: `App\Pages\Counter` → `Counter`.
 */
final class ComponentName
{
    public static function short(string $fqcn): string
    {
        return substr(strrchr('\\'.$fqcn, '\\'), 1);
    }
}
