<?php

declare(strict_types=1);

namespace Rephp\Path;

/**
 * A #[Path] property or parameter of a page, with a named type.
 */
final class PathProp
{
    public function __construct(
        public readonly string $page,
        public readonly string $name,
        public readonly \ReflectionNamedType $type,
    ) {
    }
}
