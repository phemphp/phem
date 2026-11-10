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
 * Announces messages to screen readers after the response of an event, with no toast on the
 * screen: it sends an `announce` browser event (see Phem\BrowserEventDispatcher), and the
 * browser puts the message in a hidden live region (assets/dist/phem_announcer.js).
 *
 * A handler calls `$e->announce()`. A component or a service that the handler calls gets this
 * class with autowiring (the `phem.announcements` service):
 *
 *     public function __construct(private readonly Announcements $announcements) {}
 *
 *     $this->announcements->announce('3 results');
 *
 * The messages follow the rules of the browser events: a handler that fails or that returns a
 * redirect announces none, and a message of the work of an #[Async] handler logs a warning.
 */
final class Announcements
{
    /** The name of the browser event. */
    public const EVENT = 'announce';

    public function __construct(
        private readonly BrowserEventDispatcher $dispatcher,
    ) {
    }

    /**
     * @throws \InvalidArgumentException for an empty message
     */
    public function announce(string $message): void
    {
        if ('' === trim($message)) {
            throw new \InvalidArgumentException('The message of an announcement cannot be empty.');
        }

        $this->dispatcher->dispatch(self::EVENT, ['message' => $message]);
    }

    /**
     * The message of the detail of an `announce` event, as the browser reads it: `{message}`
     * or a text. Null for a detail that the browser does not announce (an empty message).
     */
    public static function messageOf(mixed $detail): ?string
    {
        $message = \is_array($detail) ? $detail['message'] ?? null : $detail;

        return \is_string($message) && '' !== trim($message) ? $message : null;
    }
}
