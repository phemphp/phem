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
 * A context that a class component provides (see Phem\Attribute\Provide): a #[Provide] property,
 * or the component itself for #[Provide] on the class.
 */
final class ProvidedContext
{
    /**
     * @param class-string             $type     the declared type of the property, or the class
     * @param \ReflectionProperty|null $property the property, or null for the component itself
     */
    public function __construct(
        public readonly string $type,
        public readonly ?\ReflectionProperty $property = null,
    ) {
    }

    /** The context that the component provides now: null when the property has no value yet. */
    public function valueOf(object $component): mixed
    {
        if (null === $this->property) {
            return $component;
        }

        return $this->property->isInitialized($component) ? $this->property->getValue($component) : null;
    }
}
