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

namespace Phem\EventListener;

use Phem\Stream\Streams;
use Phem\Stream\Subscription;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * At the end of a request: sets the cookie of the Mercure hub for a page with #[Subscribe] (see
 * Subscription), and publishes the streams of the request (see Streams::flush()). The event
 * handler and its transaction ended before the response, so a refresh reads the new data.
 */
final class StreamListener
{
    public function __construct(
        private readonly Streams $streams,
        private readonly Subscription $subscription,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (null !== $cookie = $this->subscription->cookie($event->getRequest())) {
            $event->getResponse()->headers->setCookie($cookie);
        }

        $this->streams->flush();
    }
}
