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

namespace Phem\Argument;

use Phem\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Gives the event to a parameter of a handler with the type Event, at any position. A handler
 * that does not need the event does not declare it.
 */
final class EventValueResolver implements ValueResolverInterface
{
    /** @return iterable<Event> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $event = $request->attributes->get(CallableArguments::EVENT);
        if (!$event instanceof Event || Event::class !== $argument->getType()) {
            return [];
        }

        return [$event];
    }
}
