<?php

declare(strict_types=1);

namespace Mamu\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The JSON body of an event (`POST <page URL>`), from the `mamu` Stimulus controller. The page
 * comes from the route, not from the body.
 */
final class EventPayload
{
    /**
     * @param string               $state     the signed JSON of the page state
     * @param array<string, mixed> $event     the DOM event (type, value, checked, key, form)
     */
    public function __construct(
        public readonly string $handler,
        public readonly string $state,
        public readonly string $signature,
        public readonly array $event,
    ) {
    }

    /** 
     * @throws BadRequestHttpException when the body has no handler 
     */
    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true);
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
