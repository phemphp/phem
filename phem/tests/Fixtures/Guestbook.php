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

/** The entries of the push.phpm page, in memory: the requests of one test share them. */
final class Guestbook
{
    /** @var array<string, list<array{id: int, text: string}>> room => entries, the newest first */
    private array $entries = [];
    private int $id = 0;

    /** @return array{id: int, text: string} */
    public function add(string $room, string $text): array
    {
        $entry = ['id' => ++$this->id, 'text' => $text];
        $this->entries[$room] ??= [];
        array_unshift($this->entries[$room], $entry);

        return $entry;
    }

    /** Removes the newest entry of a room, and returns its id. */
    public function forget(string $room): ?int
    {
        $entry = array_shift($this->entries[$room]);

        return $entry['id'] ?? null;
    }

    /** @return list<array{id: int, text: string}> */
    public function entries(string $room): array
    {
        return $this->entries[$room] ??= [];
    }
}
