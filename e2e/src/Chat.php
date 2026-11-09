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

use Phem\Handler\ObjectIdentifier;
use Phem\Path\EntityFinder;

/**
 * The messages of the chat rooms, in a SQLite file: the requests of all the browsers share them. A
 * new room has five messages. As Doctrine, it gives the same object for the same row (an identity
 * map), finds a message by its identifier, and identifies a message by its id.
 */
final class Chat implements EntityFinder, ObjectIdentifier
{
    private const TABLE = 'CREATE TABLE IF NOT EXISTS message (id INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL, text TEXT NOT NULL)';

    /** @var array<int, ChatMessage> */
    private array $messages = [];

    public function __construct(private readonly Database $database)
    {
    }

    public function add(string $room, string $text): ChatMessage
    {
        $this->pdo()->prepare('INSERT INTO message (room, text) VALUES (?, ?)')->execute([$room, $text]);

        return $this->message(['id' => (int) $this->pdo()->lastInsertId(), 'room' => $room, 'text' => $text]);
    }

    public function save(ChatMessage $message): void
    {
        $this->pdo()->prepare('UPDATE message SET text = ? WHERE id = ?')->execute([$message->text, $message->id]);
    }

    public function remove(ChatMessage $message): void
    {
        $this->pdo()->prepare('DELETE FROM message WHERE id = ?')->execute([$message->id]);
        unset($this->messages[$message->id]);
    }

    /** @return list<ChatMessage> the newest messages, the oldest first */
    public function latest(string $room, int $count): array
    {
        $this->seed($room);

        return array_reverse($this->select('SELECT * FROM message WHERE room = ? ORDER BY id DESC LIMIT ?', [$room, $count]));
    }

    /** @return list<ChatMessage> the messages before a message, the oldest first */
    public function before(string $room, int $id, int $count): array
    {
        return array_reverse($this->select('SELECT * FROM message WHERE room = ? AND id < ? ORDER BY id DESC LIMIT ?', [$room, $id, $count]));
    }

    public function supports(string $class): bool
    {
        return ChatMessage::class === $class;
    }

    public function find(string $class, mixed $id): ?object
    {
        return $this->select('SELECT * FROM message WHERE id = ?', [\is_array($id) ? ($id['id'] ?? null) : $id])[0] ?? null;
    }

    public function identify(object $value): ?array
    {
        return $value instanceof ChatMessage ? ['id' => $value->id] : null;
    }

    private function seed(string $room): void
    {
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM message WHERE room = ?');
        $count->execute([$room]);
        if (0 === (int) $count->fetchColumn()) {
            foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $text) {
                $this->add($room, $text);
            }
        }
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<ChatMessage>
     */
    private function select(string $sql, array $values): array
    {
        $select = $this->pdo()->prepare($sql);
        $select->execute($values);

        return array_map($this->message(...), $select->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function message(array $row): ChatMessage
    {
        $message = $this->messages[(int) $row['id']] ??= new ChatMessage((int) $row['id'], (string) $row['room'], (string) $row['text']);
        // Another browser can change the row between two requests.
        $message->text = (string) $row['text'];

        return $message;
    }

    private function pdo(): \PDO
    {
        return $this->database->connect(self::TABLE);
    }
}
