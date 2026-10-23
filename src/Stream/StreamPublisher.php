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

namespace Phem\Stream;

/**
 * Sends an update to the subscribers of its topics (the Mercure hub of FrankenPHP, see
 * FrankenPhpPublisher). The tests keep the updates (see MemoryPublisher).
 */
interface StreamPublisher
{
    /**
     * @param list<string> $topics
     * @param string       $data    the Turbo Streams
     * @param bool         $private whether only the subscribers with a token for a topic get it
     */
    public function publish(array $topics, string $data, bool $private): void;
}
