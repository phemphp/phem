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

use Phem\Event;

/**
 * A middleware that needs the arguments of the call of the handler in the markup:
 * `onClick={{ delete(item) }}` gives `['item' => $item]` to `delete(Item $item)`.
 *
 * HandlerInvoker calls handleCall() in place of handle(). The other arguments (the services, the
 * Request, a #[MapForm] object) come after the middlewares, so they are not in the list.
 */
interface HandlerCallMiddleware extends HandlerMiddleware
{
    /**
     * @param array<string, mixed> $arguments the arguments of the call, by parameter name
     * @param \Closure(): mixed    $next      runs the next middleware, then the handler
     */
    public function handleCall(\Closure $handler, array $arguments, Event $event, \Closure $next): mixed;
}
