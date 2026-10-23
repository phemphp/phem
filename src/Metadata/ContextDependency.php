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

namespace Phem\Metadata;

/**
 * A #[Context] property of a class component, or a #[Context] parameter of a function component:
 * the component reads the context of this type from the nearest provider above it.
 */
final class ContextDependency
{
    /**
     * @param class-string             $type     the key of the context: a class, an interface or an enum
     * @param bool                     $required no default value, and the type does not accept null
     * @param mixed                    $default  the value when no provider is above the component
     * @param \ReflectionProperty|null $property the property of a class component, or null for a parameter
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required,
        public readonly mixed $default = null,
        public readonly ?\ReflectionProperty $property = null,
    ) {
    }
}
