<?php

declare(strict_types=1);

namespace Rephp\Tests\Fixtures;

use Rephp\Rendering\EntityFinder;

/** Finds the Scientist entities in memory, as Doctrine does: by identifier, or by criteria. */
final class ScientistFinder implements EntityFinder
{
    public function supports(string $class): bool
    {
        return Scientist::class === $class;
    }

    public function find(string $class, mixed $id): ?object
    {
        foreach ([new Scientist(1, 'Ada'), new Scientist(2, 'Marie')] as $scientist) {
            $criteria = \is_array($id) ? $id : ['id' => $id];
            if (array_all($criteria, static fn (mixed $value, string $field): bool => (string) $scientist->{$field} === (string) $value)) {
                return $scientist;
            }
        }

        return null;
    }
}
