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
use Phem\RedirectTo;
use Phem\Routing\PageUrlGenerator;

/**
 * Gives a handler that returns a Phem\RedirectTo its RedirectResponse: the browser visits the URL
 * of the page.
 */
final class RedirectMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly PageUrlGenerator $urls,
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        $result = $next();

        return $result instanceof RedirectTo ? $this->urls->redirect($result) : $result;
    }
}
