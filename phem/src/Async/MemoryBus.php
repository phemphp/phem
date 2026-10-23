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

namespace Phem\Async;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Messenger bus of the #[Async] handlers in the tests (`framework.test: true`, see
 * Phem\DependencyInjection\TestAsyncPass). After keep(), it keeps their messages in memory: a
 * ComponentTester runs them with settle(), as a worker does (see Phem\Test\ComponentTester).
 * Before, it sends them to the bus of the application.
 */
final class MemoryBus implements MessageBusInterface
{
    /** @var list<AsyncHandlerMessage> */
    private array $messages = [];
    private bool $keep = false;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (!$this->keep || !$message instanceof AsyncHandlerMessage) {
            return $this->bus->dispatch($message, $stamps);
        }

        $this->messages[] = $message;

        return Envelope::wrap($message, $stamps);
    }

    /** Keeps the messages from now on (see InteractsWithPhem::visit()). */
    public function keep(): void
    {
        $this->keep = true;
    }

    /**
     * The messages that wait, in their order. They do not wait any more.
     *
     * @return list<AsyncHandlerMessage>
     */
    public function take(): array
    {
        [$messages, $this->messages] = [$this->messages, []];

        return $messages;
    }
}
