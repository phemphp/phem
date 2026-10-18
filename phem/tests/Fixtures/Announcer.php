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

use Phem\BrowserEventDispatcher;

/** A service that a handler calls: it sends a browser event with the injected dispatcher. */
final class Announcer
{
    public function __construct(private readonly BrowserEventDispatcher $dispatcher)
    {
    }

    public function announce(string $message): void
    {
        $this->dispatcher->dispatch('announced', $message);
    }
}
