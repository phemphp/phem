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

use Phem\Event;
use Phem\Handler\HandlerInvoker;
use Phem\Http\EventRequest;
use Phem\Path\PathResolver;
use Phem\Rendering\EventRenderer;
use Phem\Rendering\PageRedirect;
use Phem\Rendering\Renderer;
use Phem\Rendering\Tree;
use Phem\State\PageState;
use Phem\Stream\Streams;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs the work of an #[Async] event handler in a Messenger worker (see Phem\Attribute\Async).
 *
 * 1. It checks the HMAC of the message, and sets the user of the request (see AsyncUser).
 * 2. It builds the tree of the page from the state of the event, renders the first pass to the
 *    handler, and sets the bound values, as the event did (see EventRenderer::handlerOf()). The
 *    handler gets its props, its context, its state and the values of its closure again.
 * 3. It calls the handler again, with the data of the event and a request of the page URL with
 *    the route attributes of the event. The request has no session, no cookies and no files. It
 *    drops the pushes of this call: they went with the request.
 * 4. It runs the work of the Promise, and publishes its pushes (see Streams::flush()).
 *
 * The handler and its work run through the middleware of the handlers, with no rate limit and no
 * AsyncMiddleware (see `phem.async.handler_invoker`): a #[Transactional] handler runs its work in
 * a transaction, and a work that throws drops its pushes. The exception goes to Messenger, which
 * retries the message.
 */
final class AsyncHandler
{
    /**
     * @param string $secret the key of the HMAC of the messages (`phem.secret`)
     */
    public function __construct(
        private readonly EventRenderer $events,
        private readonly Renderer $renderer,
        private readonly HandlerInvoker $handlers,
        private readonly Streams $streams,
        private readonly string $secret,
        private readonly PathResolver $paths = new PathResolver(),
        private readonly AsyncUser $user = new AsyncUser(),
        private readonly ?RequestStack $requests = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(AsyncHandlerMessage $message): void
    {
        if (!$message->isSignedWith($this->secret)) {
            throw new UnrecoverableMessageHandlingException('The message of an async handler has no valid signature: it does not come from this application, or the secret changed.');
        }

        $this->user->run($message->token, fn () => $this->handle($message));
    }

    private function handle(AsyncHandlerMessage $message): void
    {
        $data = json_decode($message->state, true, 512, \JSON_THROW_ON_ERROR);
        $state = PageState::fromArray(\is_array($data) ? $data : []);

        try {
            $props = $this->paths->resolve($message->page, $state->values);
        } catch (\UnexpectedValueException $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('An entity of the page "%s" is not there any more.', $message->page), 0, $e);
        }

        $tree = new Tree($message->page, $state->fibers, $props, $state->values, shape: $state->shape);
        $request = Request::create($message->url, 'POST');
        $request->attributes->add($message->route);
        $this->requests?->push($request);

        try {
            try {
                $handler = $this->events->handlerOf($tree, $message->handler, $message->bound);
            } catch (PageRedirect) {
                // The page redirects now (for example its link is not valid any more): a retry
                // does not help.
                $this->logger?->warning('The page "{page}" redirects now: the async work of its handler "{handler}" does not run.', ['page' => $message->page, 'handler' => $message->handler]);

                return;
            }

            if (null === $handler) {
                // The page changed since the event (a deploy): a retry does not help.
                $this->logger?->warning('The page "{page}" has no handler "{handler}" any more: its async work does not run.', ['page' => $message->page, 'handler' => $message->handler]);

                return;
            }

            $event = Event::fromArray($message->event);

            $this->renderer->inside($tree, fn (): mixed => $this->handlers->invoke($handler, $event, EventRequest::of($request, $message->event), function (\Closure $handler, array $arguments) use ($message): void {
                $mark = $this->streams->mark();
                $promise = $handler(...$arguments);
                $this->streams->rollback($mark);

                if (!$promise instanceof Promise) {
                    // A retry runs the method body again, and it returns the same thing.
                    throw new UnrecoverableMessageHandlingException(\sprintf('An async handler of the page "%s" returned a "%s" in the worker, not a %s: it must return the same thing in the request and in the worker. The request of the worker has no session and no cookies.', $message->page, get_debug_type($promise), Promise::class));
                }

                ($promise->work)();
            }));
        } finally {
            $this->requests?->pop();
        }

        $this->streams->flush();
    }
}
