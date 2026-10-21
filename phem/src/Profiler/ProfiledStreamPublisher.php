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

namespace Phem\Profiler;

use Phem\Stream\StreamPublisher;

/**
 * Keeps each published update in the Profile, for the profiler panel (debug mode only).
 */
final class ProfiledStreamPublisher implements StreamPublisher
{
    public function __construct(
        private readonly StreamPublisher $inner,
        private readonly Profile $profile,
    ) {
    }

    public function publish(array $topics, string $data, bool $private): void
    {
        $this->inner->publish($topics, $data, $private);
        $this->profile->published($topics, $data, $private);
    }
}
