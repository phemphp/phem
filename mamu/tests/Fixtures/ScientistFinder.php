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

namespace Mamu\Tests\Fixtures;

use Mamu\Path\EntityFinder;

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
