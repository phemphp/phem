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

namespace Phem\Tests\Fixtures;

/** An entity for the tests of the #[Path] entity props. ScientistFinder finds it. */
final class Scientist
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
