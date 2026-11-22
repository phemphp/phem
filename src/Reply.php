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

namespace Phem;

/**
 * The reply of an event handler: the toasts, the screen reader messages and the browser events
 * that go with the response, and what the browser does after the morph.
 *
 *     public function save(Event $e, Reply $reply): void
 *     {
 *         $this->photos->store($e->files['photo']);
 *
 *         $reply->clearFiles();
 *         $reply->toast('Saved');
 *     }
 *
 * Each handler call gets its own Reply. The reply is an argument of the handler as the others
 * (see Phem\Argument\RequestAttributeValueResolver): the handler declares it at any position,
 * alone or with the Event, or not at all. A handler middleware gets it too (see
 * Phem\Handler\HandlerMiddleware). A handler that fails or that returns a redirect sends no browser
 * events. A component or a service that the handler calls uses Phem\Toasts,
 * Phem\Announcements or Phem\BrowserEventDispatcher: they send to the same dispatcher, in order.
 */
final class Reply
{
    private readonly Toasts $toasts;
    private readonly Announcements $announcements;
    private bool $refresh = false;
    private bool $clearFiles = false;

    /**
     * @param BrowserEventDispatcher $dispatcher sends the browser events with the response: the
     *                                           `phem.browser_event_dispatcher` service
     */
    public function __construct(
        private readonly BrowserEventDispatcher $dispatcher,
    ) {
        $this->toasts = new Toasts($dispatcher);
        $this->announcements = new Announcements($dispatcher);
    }

    /**
     * Shows a toast message after the morph (see Phem\Toasts):
     *
     *     $reply->toast('Saved');                       // no type
     *     $reply->toast('No stock', ToastType::Error);
     *     $reply->toast('The file is in your downloads.', ToastType::Success, title: 'Export complete');
     *
     * @param ToastType|null             $type     a type, or null for a toast with no type (no `data-type`), as
     *                                             the Toast of Base UI
     * @param int|null                   $duration the time on the screen in ms (up to Toasts::MAX_DURATION), `0` to
     *                                             keep the toast until its close button, or null for the
     *                                             `duration` value of the controller
     * @param string|null                $title    a short title above the message, or null for a toast with no title
     * @param string|null                $id       the id of the toast: a toast with the id of a toast on the screen updates it
     * @param string|null                $action   the label of an action button, for the `onCall` handler around the toasts
     * @param array<string, scalar|null> $data     the fields of the toast, and the data of its action
     * @param string|null                $anchor   the `id` of an element: the toast shows next to it
     * @param string|null                $viewport the `name` of the `phem-toast` element that shows the toast
     *
     * @throws \InvalidArgumentException for an empty message, an empty title (id, action, anchor,
     *                                   viewport), data that is not a map of scalars, or a
     *                                   duration out of range
     */
    public function toast(
        string $message,
        ?ToastType $type = null,
        ?int $duration = null,
        ?string $title = null,
        ?string $id = null,
        ?string $action = null,
        array $data = [],
        ?string $anchor = null,
        ?string $viewport = null,
    ): void {
        $this->toasts->show($message, $type, $duration, $title, $id, $action, $data, $anchor, $viewport);
    }

    /**
     * Announces the message to screen readers after the morph, with no toast on the screen: the
     * browser puts it in a hidden live region (`aria-live="polite"`). Use it for a change that a
     * screen reader does not see, as the new number of results of a search.
     *
     *     $reply->announce('3 results');
     *
     * A service uses Phem\Announcements. A redirect does not announce.
     *
     * @throws \InvalidArgumentException for an empty message
     */
    public function announce(string $message): void
    {
        $this->announcements->announce($message);
    }

    /**
     * Sends a browser event with the response: after the morph, the browser dispatches a
     * `CustomEvent` named `phem:<name>`, with the detail (see Phem\BrowserEventDispatcher).
     *
     *     $reply->dispatch('saved', ['id' => $this->order->id]);
     *
     * @param string $name   letters, digits and `_ - . :`, and not a name of the browser (`done`, `error`, `call`, ...)
     * @param mixed  $detail a value that JSON can encode
     *
     * @throws \InvalidArgumentException for a name that is not valid or reserved, or a detail that JSON cannot encode
     */
    public function dispatch(string $name, mixed $detail = null): void
    {
        $this->dispatcher->dispatch($name, $detail);
    }

    /**
     * Renders the full page after the handler, as `router.refresh()` of Next.js. By default, only
     * the component of the handler, the components whose state changed, and their children with
     * new props render again. Call it when the handler changes data that other components read
     * (for example, a form that adds a row to a list of the page).
     */
    public function refresh(): void
    {
        $this->refresh = true;
    }

    /**
     * Empties the file inputs that sent their files with the submit, in the browser, after the
     * morph. By default, the morph keeps the files of an input, and the next submit sends them
     * again. Call it when the handler took the files (for example, it stored them). A handler
     * that shows a validation error does not call it: the user does not select the files again.
     */
    public function clearFiles(): void
    {
        $this->clearFiles = true;
    }

    /**
     * Whether the handler asked for a render of the full page (see refresh()). The renderer reads
     * it after the handler, and a test of a handler can check it.
     */
    public function refreshes(): bool
    {
        return $this->refresh;
    }

    /**
     * Whether the handler took the files of the form (see clearFiles()). The renderer reads it
     * after the handler, and a test of a handler can check it.
     */
    public function clearsFiles(): bool
    {
        return $this->clearFiles;
    }
}
