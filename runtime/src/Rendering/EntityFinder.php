<?php

declare(strict_types=1);

namespace Rephp\Rendering;

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
