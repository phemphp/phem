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
 * Shows toast messages after the response of an event: it sends a `phem:toast` browser event
 * (see Phem\BrowserEventDispatcher), and the `phem-toast` controller shows it.
 *
 * A handler calls `$e->toast()`. A component or a service that the handler calls gets this class
 * with autowiring (the `phem.toasts` service):
 *
 *     public function __construct(private readonly Toasts $toasts) {}
 *
 *     $this->toasts->error('The schedule starts before it ends.');
 *     $this->toasts->success('The file is in your downloads.', title: 'Export complete');
 *
 * The toasts follow the rules of the browser events: a handler that fails or that returns a
 * redirect shows none. A toast shows only with the response of an event: a toast of a page
 * load does not show, and a toast of the work of an #[Async] handler logs a warning (the worker
 * has no response: use Phem\Stream\Streams there).
 */
final class Toasts
{
    /** The longest duration in ms: the browser runs a longer timer at once. */
    public const MAX_DURATION = 2_147_483_647;

    public function __construct(
        private readonly BrowserEventDispatcher $dispatcher,
    ) {
    }

    /**
     * @param ToastType|null $type a type, or null for a toast with no type (no `data-type`)
     * @param int|null    $duration the time on the screen in ms (up to MAX_DURATION), `0` to keep
     *                              the toast until its close button, or null for the `duration`
     *                              value of the controller
     * @param string|null $title    a short title above the message, or null for a toast with no title
     * @param string|null $id       the id of the toast: a toast with the id of a toast on the screen
     *                              updates it, and replays its `data-updated` animation
     * @param string|null $action   the label of an action button: a click on it calls the `onCall`
     *                              handler around the `phem-toast` element, with the `action`, the `id`
     *                              and the `data` of the toast in `$e->detail`, and closes the toast
     * @param array<string, scalar|null> $data the fields of the `[data-phem-toast-data]` parts of the
     *                              template, and the data of the action
     * @param string|null $anchor   the `id` of an element: the toast shows next to it
     * @param string|null $viewport the `name` of the `phem-toast` element that shows the toast, or
     *                              null for the element with no name
     *
     * @throws \InvalidArgumentException for an empty message, an empty title (id, action, anchor,
     *                                   viewport) or a duration out of range
     */
    public function show(
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
        if ('' === trim($message)) {
            throw new \InvalidArgumentException('The message of a toast cannot be empty.');
        }

        if (null !== $title && '' === trim($title)) {
            throw new \InvalidArgumentException('The title of a toast cannot be empty. Give null for a toast with no title.');
        }

        if (null !== $duration && ($duration < 0 || $duration > self::MAX_DURATION)) {
            throw new \InvalidArgumentException(\sprintf('The duration of a toast is from 0 to %d ms, %d given. Give 0 to keep the toast until the user closes it.', self::MAX_DURATION, $duration));
        }

        foreach (['id' => $id, 'action' => $action, 'anchor' => $anchor, 'viewport' => $viewport] as $name => $value) {
            if (null !== $value && '' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('The %s of a toast cannot be empty. Give null for a toast with no %1$s.', $name));
            }
        }

        $this->dispatcher->dispatch('toast', new Toast($message, $type, $duration, $title, $id, $action, $data, $anchor, $viewport)->detail());
    }

    public function info(string $message, ?int $duration = null, ?string $title = null): void
    {
        $this->show($message, ToastType::Info, $duration, $title);
    }

    public function success(string $message, ?int $duration = null, ?string $title = null): void
    {
        $this->show($message, ToastType::Success, $duration, $title);
    }

    public function warning(string $message, ?int $duration = null, ?string $title = null): void
    {
        $this->show($message, ToastType::Warning, $duration, $title);
    }

    public function error(string $message, ?int $duration = null, ?string $title = null): void
    {
        $this->show($message, ToastType::Error, $duration, $title);
    }
}
