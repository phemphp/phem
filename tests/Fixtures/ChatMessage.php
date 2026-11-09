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

/** A message of the chat.phem page: an entity of the feed (see Phem\Feed). */
final class ChatMessage
{
    public function __construct(
        public readonly int $id,
        public readonly string $room,
        public string $text,
    ) {
    }
}
