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

use Phem\Handler\ObjectIdentifier;
use Phem\Path\EntityFinder;

/**
 * The messages of the chat.phem page, in memory: the requests of one test share them. A new room
 * has five messages. As Doctrine, it finds a message by its identifier, always as the same object,
 * and identifies a message by its id.
 */
final class Chat implements EntityFinder, ObjectIdentifier
{
    /** @var array<string, list<ChatMessage>> room => messages, the oldest first */
    private array $rooms = [];
    private int $id = 0;

    public function add(string $room, string $text): ChatMessage
    {
        $message = new ChatMessage(++$this->id, $room, $text);
        $this->rooms[$room] = [...$this->messages($room), $message];

        return $message;
    }

    public function remove(ChatMessage $message): void
    {
        $this->rooms[$message->room] = array_values(array_filter($this->messages($message->room), static fn (ChatMessage $m): bool => $m !== $message));
    }

    /** @return list<ChatMessage> the newest messages, the oldest first */
    public function latest(string $room, int $count): array
    {
        return \array_slice($this->messages($room), -$count);
    }

    /** @return list<ChatMessage> the messages before a message, the oldest first */
    public function before(string $room, int $id, int $count): array
    {
        $older = array_values(array_filter($this->messages($room), static fn (ChatMessage $m): bool => $m->id < $id));

        return \array_slice($older, -$count);
    }

    public function supports(string $class): bool
    {
        return ChatMessage::class === $class;
    }

    public function find(string $class, mixed $id): ?object
    {
        $id = \is_array($id) ? ($id['id'] ?? null) : $id;
        foreach ($this->rooms as $messages) {
            foreach ($messages as $message) {
                if ((string) $message->id === (string) $id) {
                    return $message;
                }
            }
        }

        return null;
    }

    public function identify(object $value): ?array
    {
        return $value instanceof ChatMessage ? ['id' => $value->id] : null;
    }

    /** @return list<ChatMessage> */
    private function messages(string $room): array
    {
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
            foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $text) {
                $this->add($room, $text);
            }
        }

        return $this->rooms[$room];
    }
}
