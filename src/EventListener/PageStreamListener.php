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

use Phem\Http\PageStream;
use Symfony\Component\HttpFoundation\Session\SessionUtils;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * The response of a page that streams its `<Suspense>` boundaries (see Phem\Http\PageStream),
 * after the session listener of Symfony:
 *
 *  - No cache keeps it: a cache keeps the content of a response, and a stream has none.
 *  - The session listener saved the session. A boundary can use the session after the headers,
 *    when the session cannot start any more: it starts again here, before the headers. The
 *    stream saves it at its end.
 */
final class PageStreamListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || true !== $request->attributes->get(PageStream::ATTRIBUTE)) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');

        if (!$request->hasSession(true)) {
            return;
        }

        $session = $request->getSession();
        $session->start();
        // The session listener set the cookie on the response: PHP does not send it again.
        SessionUtils::popSessionCookie($session->getName(), $session->getId());
    }
}
