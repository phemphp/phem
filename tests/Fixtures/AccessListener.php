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

namespace Phem\Tests\Fixtures;

use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The access rules of the fixture app: a path rule, as `access_control: [{path: ^/private}]`,
 * a route name rule, as `access_control: [{route: ^route_rule$}]`, and the #[Deny] controller
 * attribute, as `#[IsGranted]`.
 */
final class AccessListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        // As the firewall: only the main request (the error page is a sub-request of the same path).
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/private/')) {
            throw new AccessDeniedHttpException('A private path.');
        }

        if ('route_rule' === $request->attributes->get('_route')) {
            throw new AccessDeniedHttpException('A private route name.');
        }
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        if ([] !== $event->getAttributes(Deny::class)) {
            throw new AccessDeniedHttpException('A #[Deny] controller.');
        }
    }
}
