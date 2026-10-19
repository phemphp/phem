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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The JSON body of an event (`POST <page URL>`), from the `phem` Stimulus controller. The page
 * comes from the route, not from the body.
 */
final class EventPayload
{
    /**
     * The maximum depth of the JSON body, as json_decode() counts it. The body from the browser
     * has a depth of 5 (the body, the event, the form fields, a [name, value] pair, and the
     * value), so a deeper body does not come from it.
     */
    public const MAX_DEPTH = 8;

    /**
     * @param string               $state the signed JSON of the page state
     * @param array<string, mixed> $event the DOM event (type, value, checked, key, form)
     */
    public function __construct(
        public readonly string $handler,
        public readonly string $state,
        public readonly string $signature,
        public readonly array $event,
    ) {
    }

    /**
     * @throws BadRequestHttpException when the body is not JSON, is too deep, or has no handler
     */
    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true, self::MAX_DEPTH);
        if (!\is_array($data) || !\is_string($data['handler'] ?? null)) {
            throw new BadRequestHttpException('An event needs a handler.');
        }

        return new self(
            $data['handler'],
            (string) ($data['state'] ?? '{}'),
            (string) ($data['signature'] ?? ''),
            \is_array($data['event'] ?? null) ? $data['event'] : [],
        );
    }
}
