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

/**
 * The Messenger message of an #[Async] event handler (see Phem\Attribute\Async): what the worker
 * needs to render the page to the handler again, as the event did. Route it to a transport
 * (`framework.messenger.routing`), or it runs at once, in the request.
 *
 * The message has an HMAC of its fields with the secret of Phem: the worker rejects a message
 * that a writer to the queue made or changed. The signature of the browser does not work here:
 * with `state_bind_session`, it needs the session of the request.
 */
final class AsyncHandlerMessage
{
    /**
     * @param string                  $page    the page class
     * @param string                  $url     the URL of the page, for the request of the Event
     * @param array<string, mixed>    $route   the route attributes of the request (`_route`, `_route_params` and the route values)
     * @param string                  $state   the state JSON of the event, before the handler
     * @param array<array-key, mixed> $bound   the bound values of the event (see Phem\State\BoundValues)
     * @param string                  $handler the handler id
     * @param array<string, mixed>    $event   the data of the event (see Phem\Event::fromArray())
     * @param string|null             $token   the security token of the user, serialized (see AsyncUser)
     */
    public function __construct(
        public readonly string $page,
        public readonly string $url,
        public readonly array $route,
        public readonly string $state,
        public readonly array $bound,
        public readonly string $handler,
        public readonly array $event,
        public readonly ?string $token,
        public readonly string $signature = '',
    ) {
    }

    /** The same message, signed with a secret. */
    public function signed(string $secret): self
    {
        return new self($this->page, $this->url, $this->route, $this->state, $this->bound, $this->handler, $this->event, $this->token, $this->hash($secret));
    }

    public function isSignedWith(string $secret): bool
    {
        return '' !== $this->signature && hash_equals($this->hash($secret), $this->signature);
    }

    private function hash(string $secret): string
    {
        return hash_hmac('sha256', json_encode([$this->page, $this->url, $this->route, $this->state, $this->bound, $this->handler, $this->event, $this->token], \JSON_THROW_ON_ERROR), $secret);
    }
}
