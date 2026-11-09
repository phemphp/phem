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
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * At the end of a request: sets the cookie of the Mercure hub for a page with #[Broadcast] (see
 * Subscription), and publishes the streams of the request (see Streams::flush()). The event
 * handler and its transaction ended before the response, so a refresh reads the new data. The
 * `<Suspense>` boundaries of a streamed page render after the response event (see
 * Phem\Http\PageRenderer): their streams go after the response.
 *
 * A command and a Messenger worker have no request: the streams of a command go at its end, and
 * the streams of a message after its handler (for example the refreshes of a flush, see
 * Phem\Doctrine\EntityBroadcastListener), before the worker resets the services.
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

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->streams->flush();
    }

    /** The end of a command (`console.terminate`), or of a message of a Messenger worker. */
    public function onProcessEnd(): void
    {
        $this->streams->flush();
    }
}
