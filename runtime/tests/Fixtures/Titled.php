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

use Mamu\Attribute\State;

/** The parent class of a fixture component, outside the .phpm sources: mamuc does not see it. */
abstract class Titled
{
    public string $title = 'Untitled';
    #[State] public int $hits = 0;
    public function hit(): void
    {
        ++$this->hits;
    }
}
