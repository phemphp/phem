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

use Phem\Attribute\Context;

/**
 * A parameter of a function component: a prop, or a context (see Phem\Attribute\Context).
 */
final class Parameter
{
    public function __construct(
        public readonly string $name,
        public readonly bool $optional,
        public readonly mixed $default,
        public readonly bool $hasDefault,
        public readonly bool $nullable,
        public readonly bool $variadic,
        /** A #[Context] parameter: the context gives it, not the props (see ContextMetadata). */
        public readonly bool $context = false,
        /** The declared type: a PropConverter converts a prop value to it. */
        public readonly ?\ReflectionType $type = null,
    ) {
    }

    public static function fromReflection(\ReflectionParameter $p): self
    {
        $hasDefault = $p->isDefaultValueAvailable();

        return new self(
            $p->getName(),
            $p->isOptional(),
            $hasDefault ? $p->getDefaultValue() : null,
            $hasDefault,
            $p->allowsNull(),
            $p->isVariadic(),
            [] !== $p->getAttributes(Context::class),
            $p->getType(),
        );
    }
}
