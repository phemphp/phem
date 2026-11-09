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

use Phem\Path\EntityFinder;

/** Finds the entities of the fixture pages: the Scientist of a #[Path] prop, and the ChatMessage of a feed. */
final class FixtureFinder implements EntityFinder
{
    public function __construct(
        private readonly ScientistFinder $scientists,
        private readonly Chat $chat,
    ) {
    }

    public function supports(string $class): bool
    {
        return $this->scientists->supports($class) || $this->chat->supports($class);
    }

    public function find(string $class, mixed $id): ?object
    {
        return $this->chat->supports($class) ? $this->chat->find($class, $id) : $this->scientists->find($class, $id);
    }
}
