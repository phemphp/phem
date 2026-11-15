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
     * @param int|null    $duration the time on the screen in ms (up to MAX_DURATION), `0` to keep
     *                              the toast until its close button, or null for the `duration`
     *                              value of the controller
     * @param string|null $title    a short title above the message, or null for a toast with no title
     *
     * @throws \InvalidArgumentException for an empty message, an empty title or a duration out of range
     */
    public function show(string $message, ToastType $type = ToastType::Info, ?int $duration = null, ?string $title = null): void
    {
        if ('' === trim($message)) {
            throw new \InvalidArgumentException('The message of a toast cannot be empty.');
        }

        if (null !== $title && '' === trim($title)) {
            throw new \InvalidArgumentException('The title of a toast cannot be empty. Give null for a toast with no title.');
        }

        if (null !== $duration && ($duration < 0 || $duration > self::MAX_DURATION)) {
            throw new \InvalidArgumentException(\sprintf('The duration of a toast is from 0 to %d ms, %d given. Give 0 to keep the toast until the user closes it.', self::MAX_DURATION, $duration));
        }

        $this->dispatcher->dispatch('toast', new Toast($message, $type, $duration, $title)->detail());
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
