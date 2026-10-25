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
 * The token of an event that refreshes the component of its handler (see
 * Phem\Handler\BroadcastMiddleware). The refresh has it, and the answer of the event gives it to
 * the browser that sent the event: that browser does not render the component of the handler
 * again, the event rendered it already.
 *
 * A random value of each event, from the server: another viewer sees it in the push only after
 * the event, and cannot make the refresh of another event skip a component.
 */
final class SenderToken implements ResetInterface
{
    private ?string $token = null;

    /** The token of this event: a new one at the first call. */
    public function token(): string
    {
        return $this->token ??= bin2hex(random_bytes(12));
    }

    /** The token of this event, or null when no refresh has it. */
    public function issued(): ?string
    {
        return $this->token;
    }

    public function reset(): void
    {
        $this->token = null;
    }
}
