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

namespace App;

/** A message of a chat room: an item of the feed of ChatRoom (see Phem\Feed). */
final class ChatMessage
{
    public function __construct(
        public readonly int $id,
        public readonly string $room,
        public string $text,
    ) {
    }
}
