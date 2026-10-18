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

namespace Mamu\Metadata;

use Mamu\HtmlList;

/**
 * A parameter of a function component: a prop.
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
        /** The type takes an array, but not an HtmlList (see HtmlList::wantsArray()). */
        public readonly bool $wantsArray = false,
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
            HtmlList::wantsArray($p->getType()),
        );
    }
}
