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

namespace Phem\Path;

/**
 * Finds the entity of a `#[Path]` prop with a class type, from its route value.
 */
interface EntityFinder
{
    /** Whether the class is an entity that this finder can find. */
    public function supports(string $class): bool;

    /**
     * @param class-string                    $class
     * @param string|int|array<string, mixed> $id    the identifier, or the criteria (field => value)
     *
     * @return object|null null when no entity matches
     */
    public function find(string $class, mixed $id): ?object;
}
