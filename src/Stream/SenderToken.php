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

namespace Phem\Stream;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The token of the refreshes of an event (see Streams): each refresh that an event request sends
 * has it, from a handler with #[Broadcast] or from any service (a Doctrine listener). The answer
 * of the event gives the browser that sent it the token and the fibers that the event rendered:
 * that browser does not render these fibers again, the event rendered them already. It renders
 * the other targets of the refresh.
 *
 * A random value of each event, from the server: another viewer sees it in the push only after
 * the event, and cannot make the refresh of another event skip a component. A worker or a
 * command answers no browser: its refreshes have no token.
 */
final class SenderToken implements ResetInterface
{
    private bool $event = false;
    private bool $refresh = false;
    private ?string $token = null;

    /**
     * The handlers of an event of a browser run: their refreshes get the token (see EventController).
     *
     * @param bool $refresh the event has no handler: the browser renders again after a push
     */
    public function startEvent(bool $refresh = false): void
    {
        $this->event = true;
        $this->refresh = $refresh;
    }

    /** The handlers of the event ended: issued() keeps the token for the answer of the event. */
    public function endEvent(): void
    {
        $this->event = false;
        $this->refresh = false;
    }

    /**
     * Whether an event with no handler runs: the render of a push refresh. A write in it must not
     * refresh the other browsers (see Phem\Doctrine\EntityBroadcastListener).
     */
    public function isRefresh(): bool
    {
        return $this->event && $this->refresh;
    }

    /** The token for a refresh: in an event, a new one at the first call. Else null. */
    public function forRefresh(): ?string
    {
        return $this->event ? $this->token ??= bin2hex(random_bytes(12)) : null;
    }

    /** The token of this event, or null when no refresh has it. */
    public function issued(): ?string
    {
        return $this->token;
    }

    public function reset(): void
    {
        $this->event = false;
        $this->refresh = false;
        $this->token = null;
    }
}
