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

use Phem\Attribute\Path;
use Phem\Attribute\Subscribe;

/** A page with #[Subscribe] and two #[Path] props, for the topics (see Phem\Stream\Topics). */
#[Subscribe]
final class Subscribed
{
    #[Path] public int $id;
    #[Path] public Size $size;
}
