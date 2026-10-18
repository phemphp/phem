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
 * a `CustomEvent` named `phem:<name>` on `window`, with the detail. Stimulus controllers and
 * plain JS listen to it (`data-action="phem:toast@window->toast#show"`).
 *
 * A handler gets it from its event:
 *
 *     public function save(Event $e): void
 *     {
 *         $e->dispatcher->dispatch('toast', ['message' => 'Saved']);
 *     }
 *
 * The events go in their order. A handler that fails or that returns a redirect sends none.
 */
final class BrowserEventDispatcher
{
    /** @var list<array{name: string, detail: mixed}> */
    private array $dispatched = [];

    /**
     * @param string $name   letters, digits and `_ - . :`
     * @param mixed  $detail a value that JSON can encode
     *
     * @throws \InvalidArgumentException for a name that is not valid, or a detail that JSON cannot encode
     */
    public function dispatch(string $name, mixed $detail = null): void
    {
        if (1 !== preg_match('/^[\w.:-]+$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('The name of a browser event has only letters, digits and "_-.:", "%s" given.', $name));
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
}
