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
 * Sends browser events with the response of an event: after the morph, the browser dispatches
 * a `CustomEvent` named `phem:<name>`, with the detail. It starts on the root of the component
 * of the handler (the element with its fiber id, or the root element of the page) and bubbles up to `window`.
 * Stimulus controllers and plain JS listen to it on an ancestor (`data-action="phem:saved->chart#reload"`)
 * or on `window` (`data-action="phem:saved@window->chart#reload"`).
 *
 * A handler gets it from its event:
 *
 *     public function save(Event $e): void
 *     {
 *         $e->dispatcher->dispatch('saved', ['id' => $this->order->id]);
 *     }
 *
 * For a toast message, use `$e->toast()` or Phem\Toasts: they dispatch a `toast` event.
 *
 * It is also a service (`phem.browser_event_dispatcher`), so a component or a service that the
 * handler calls can get it with autowiring. `$e->dispatcher` is the same object.
 *
 * The events go in their order. A handler that fails or that returns a redirect sends none.
 * The events of the render before the handler are not sent.
 */
final class BrowserEventDispatcher
{
    /**
     * The names of the events that the browser uses itself: it dispatches `phem:done`, ..., and
     * a `phem:call` of the JS code calls an `onCall` handler.
     */
    private const RESERVED = ['call', 'before-queue', 'queue', 'send', 'receive', 'done', 'error', 'rate-limited', 'upload-progress', 'clear-files'];

    /** @var list<array{name: string, detail: mixed}> */
    private array $dispatched = [];

    /**
     * @param string $name   letters, digits and `_ - . :`, and not a name of the browser (`done`, `error`, ...)
     * @param mixed  $detail a value that JSON can encode
     *
     * @throws \InvalidArgumentException for a name that is not valid or reserved, or a detail that JSON cannot encode
     */
    public function dispatch(string $name, mixed $detail = null): void
    {
        if (1 !== preg_match('/\A[\w.:-]+\z/', $name)) {
            throw new \InvalidArgumentException(\sprintf('The name of a browser event has only letters, digits and "_-.:", "%s" given.', $name));
        }

        if (\in_array($name, self::RESERVED, true)) {
            throw new \InvalidArgumentException(\sprintf('The browser uses the "phem:%s" event itself: give the browser event another name.', $name));
        }

        try {
            json_encode($detail, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(\sprintf('The detail of the browser event "%s" cannot be JSON: %s.', $name, $e->getMessage()), 0, $e);
        }

        $this->dispatched[] = ['name' => $name, 'detail' => $detail];
    }

    /**
     * The browser events of dispatch(), in their order.
     *
     * @return list<array{name: string, detail: mixed}>
     *
     * @internal
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    /**
     * Removes the browser events, before a handler runs and between two requests.
     *
     * @internal
     */
    public function reset(): void
    {
        $this->dispatched = [];
    }
}
