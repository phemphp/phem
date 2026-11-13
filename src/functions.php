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

namespace Phem;

/**
 * A mergeable `class` value (see ClassList): `classes('px-4 py-2', ['active' => $isActive])`.
 * Use it in PHP code, for example for the classes of a variant. In markup, the Twig function
 * `classes()` is the same.
 */
function classes(mixed ...$inputs): ClassList
{
    return ClassList::of(...$inputs);
}

/**
 * The classes of the variants of a component (see Variants):
 * `variants(base: 'px-4', variants: ['size' => ['sm' => 'text-sm']])->apply(size: $size)`.
 *
 * @param array<string, array<string|int, mixed>>  $variants
 * @param list<array{array<string, mixed>, mixed}> $compound
 */
function variants(mixed $base = null, array $variants = [], array $compound = []): Variants
{
    return new Variants($base, $variants, $compound);
}
