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

/** The entries of the guestbook rooms, in the Database of the e2e pages. */
final class Guestbook
{
    private const TABLE = 'CREATE TABLE IF NOT EXISTS entry (id INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL, text TEXT NOT NULL)';

    public function __construct(private readonly Database $database)
    {
    }

    /** @return array{id: int, text: string} */
    public function add(string $room, string $text): array
    {
        $insert = $this->pdo()->prepare('INSERT INTO entry (room, text) VALUES (?, ?)');
        $insert->execute([$room, $text]);

        return ['id' => (int) $this->pdo()->lastInsertId(), 'text' => $text];
    }

    /** @return list<array{id: int, text: string}> the newest first */
    public function entries(string $room): array
    {
        $select = $this->pdo()->prepare('SELECT id, text FROM entry WHERE room = ? ORDER BY id DESC');
        $select->execute([$room]);

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'text' => (string) $row['text']], $select->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function pdo(): \PDO
    {
        return $this->database->connect(self::TABLE);
    }
}
