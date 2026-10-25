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

namespace Phem\Handler;

use Phem\Async\Promise;
use Phem\Attribute\Broadcast;
use Phem\Controller\EventController;
use Phem\Event;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Renderer;
use Phem\Stream\BroadcastKeys;
use Phem\Stream\SenderToken;
use Phem\Stream\Streams;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Refreshes a class component with #[Broadcast] in the other browsers after its event handler
 * (see Phem\Attribute\Broadcast): Streams::toComponent() with the values of its key props, and
 * refresh() with no target.
 *
 * - With #[Broadcast] on some handler methods of the class, only these handlers refresh it, and
 *   the class needs no #[Broadcast] (see ComponentMetadata::broadcasts()). A closure of render()
 *   that calls such a method needs #[Broadcast] too: the middleware cannot see the call.
 * - The component of a handler is the object of its closure, as for #[Transactional]: a handler
 *   that a parent passes as a prop refreshes the parent. A function component has no object,
 *   and a page has no instances on other pages: they do not refresh.
 * - It is the last middleware before the handler: a middleware that stops the event refreshes
 *   nothing. A handler that throws refreshes nothing, and a commit that fails drops the refresh
 *   (see StreamsMiddleware). An #[Async] handler refreshes after its work, in the worker.
 * - The refresh of an event has its SenderToken: the browser that sent the event does not
 *   render the component of the handler again, the event rendered it already. Its other
 *   instances render again.
 */
final class BroadcastMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly Streams $streams,
        private readonly Renderer $renderer,
        private readonly BroadcastKeys $keys = new BroadcastKeys(),
        private readonly SenderToken $sender = new SenderToken(),
        private readonly ?RequestStack $requests = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $result = $next();

        // The worker runs the work, and refreshes after it.
        if ($result instanceof Promise) {
            return $result;
        }

        $function = new \ReflectionFunction($handler);
        $component = $function->getClosureThis();
        if (null === $component || !$this->broadcasts($component, $function)) {
            return $result;
        }

        // A page with #[Broadcast] has no key: it gets the streams of other handlers only.
        $fiber = $this->renderer->fiberOf($component);
        if (null === $fiber || null === $this->keys->props($fiber->fqcn)) {
            return $result;
        }

        $streams = $this->streams->toComponent($fiber->fqcn, $this->keys->valuesOf($fiber));

        // The browser of an event gets the token in the answer; a worker answers no browser.
        if (true === $this->requests?->getCurrentRequest()?->headers->has(EventController::HEADER)) {
            $streams->refreshExcept($this->sender->token(), $fiber->id);
        } else {
            $streams->refresh();
        }

        return $result;
    }

    /**
     * Whether the handler refreshes its component: the component has #[Broadcast], and the
     * handler has it too when some handler methods have it. A closure of render() has no method
     * name: it needs its own #[Broadcast] (`#[Broadcast] fn () => $this->like($id)`).
     *
     * @throws \LogicException when a closure has #[Broadcast], and its component does not broadcast
     */
    private function broadcasts(object $component, \ReflectionFunction $handler): bool
    {
        $closure = [] !== $handler->getAttributes(Broadcast::class);
        if (!$this->metadata->broadcasts($component::class)) {
            if ($closure) {
                throw new \LogicException(\sprintf('A handler closure of "%s" has #[Broadcast], but the component does not broadcast: add #[Broadcast] to the class or to a handler method, so its pages connect to the hub.', $component::class));
            }

            return false;
        }

        $handlers = $this->metadata->broadcastHandlers($component::class);

        return $closure || [] === $handlers || \in_array($handler->getName(), $handlers, true);
    }
}
