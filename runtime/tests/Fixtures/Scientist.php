<?php

declare(strict_types=1);

namespace Mamu\Tests\Fixtures;

/** An entity for the tests of the #[Path] entity props. ScientistFinder finds it. */
final class Scientist
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
