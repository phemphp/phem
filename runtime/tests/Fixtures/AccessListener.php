<?php

declare(strict_types=1);

namespace Mamu\Tests\Fixtures;

use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The access rules of the fixture app: a path rule, as `access_control: [{path: ^/private}]`,
 * and the #[Deny] controller attribute, as `#[IsGranted]`.
 */
final class AccessListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        // As the firewall: only the main request (the error page is a sub-request of the same path).
        if ($event->isMainRequest() && str_starts_with($event->getRequest()->getPathInfo(), '/private/')) {
            throw new AccessDeniedHttpException('A private path.');
        }
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        if ([] !== $event->getAttributes(Deny::class)) {
            throw new AccessDeniedHttpException('A #[Deny] controller.');
        }
    }
}
