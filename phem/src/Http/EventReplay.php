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

namespace Phem\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * What a process needs to run the handler of an event again, as the EventController ran it: the
 * page, the state JSON before the handler, the bound values, the handler id and the DOM event.
 * The EventController sets it on the request before the handler runs. An #[Async] handler sends
 * it to a worker (see Phem\Async\AsyncDispatcher).
 */
final class EventReplay
{
    /** The request attribute. */
    public const ATTRIBUTE = '_phem_event';

    /**
     * @param string                  $page    the page class
     * @param string                  $state   the state JSON of the event, before the handler
     * @param array<array-key, mixed> $bound   the bound values of the event (see Phem\State\BoundValues)
     * @param string                  $handler the handler id
     * @param array<string, mixed>    $event   the data of the event (see Phem\Event::fromArray())
     */
    public function __construct(
        public readonly string $page,
        public readonly string $state,
        public readonly array $bound,
        public readonly string $handler,
        public readonly array $event,
    ) {
    }

    /** The replay of the event of a request, or null for a request that is not an event. */
    public static function of(Request $request): ?self
    {
        $replay = $request->attributes->get(self::ATTRIBUTE);

        return $replay instanceof self ? $replay : null;
    }
}
