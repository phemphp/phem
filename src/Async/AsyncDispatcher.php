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

use Phem\Http\EventReplay;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches the AsyncHandlerMessage of an #[Async] event handler that returned a Promise (see
 * Phem\Attribute\Async). The AsyncMiddleware calls it in the transaction of the handler: a
 * dispatch that fails rolls back the event.
 */
final class AsyncDispatcher
{
    /**
     * @param string $secret the key of the HMAC of the messages (`phem.secret`)
     */
    public function __construct(
        private readonly ?MessageBusInterface $bus,
        private readonly string $secret,
        private readonly AsyncUser $user = new AsyncUser(),
    ) {
    }

    /**
     * Checks that the event of a request can run an #[Async] handler. The AsyncMiddleware calls
     * it before an #[Async] method: a failed check runs no handler.
     *
     * @throws \LogicException with no Messenger, out of an event of a page, or for an event with files
     */
    public function check(?Request $request): void
    {
        $this->replay($request);
    }

    /**
     * @throws \LogicException when check() fails
     */
    public function dispatch(?Request $request): void
    {
        [$bus, $replay, $request] = $this->replay($request);
        $bus->dispatch(new AsyncHandlerMessage($replay->page, $request->getUri(), self::routeOf($request), $replay->state, $replay->bound, $replay->handler, $replay->event, $this->user->capture(), item: null !== $replay->item ? ['feed' => $replay->item->fiber.'-'.$replay->item->feed, 'token' => $replay->item->token] : null)->signed($this->secret));
    }

    /** @return array{MessageBusInterface, EventReplay, Request} */
    private function replay(?Request $request): array
    {
        $bus = $this->bus ?? throw new \LogicException('An #[Async] handler needs Symfony Messenger: install symfony/messenger and enable framework.messenger.');
        $replay = null !== $request ? EventReplay::of($request) : null;

        if (null === $request || null === $replay) {
            throw new \LogicException('An #[Async] handler runs only in an event of a page: the worker renders the page of the event again. In a test, use visit() and settle(), not mount().');
        }

        if ([] !== $request->files->all()) {
            throw new \LogicException('An #[Async] handler cannot get the files of a form, a drop or a paste: PHP removes them at the end of the request. Move the files in a normal handler.');
        }

        return [$bus, $replay, $request];
    }

    /**
     * The route attributes of the request (`_route`, `_route_params` and the route values): the
     * request of the worker gets them again.
     *
     * @return array<string, mixed>
     */
    private static function routeOf(Request $request): array
    {
        $params = (array) $request->attributes->get('_route_params', []);

        return [...$params, '_route' => $request->attributes->get('_route'), '_route_params' => $params];
    }
}
