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

use Phem\Attribute\Component;
use Phem\Attribute\Prop;
use Phem\Attribute\Subscribe;

/** A component with #[Subscribe], for the topics (see Phem\Stream\Topics). */
#[Component, Subscribe]
final class SubscribedComponent
{
    #[Prop] public string $label = '';
}
