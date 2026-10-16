<?php

declare(strict_types=1);

namespace Rephp\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The JSON body of `POST /_rhp/event`, from the `rhp` Stimulus controller.
 */
final class EventPayload
{
    /**
     * @param string               $state     the signed JSON of the page state
     * @param array<string, mixed> $event     the DOM event (type, value, checked, key, form)
     */
    public function __construct(
        public readonly string $page,
        public readonly string $handler,
        public readonly string $state,
        public readonly string $signature,
        public readonly array $event,
    ) {
    }

    /** @throws BadRequestHttpException when the body has no page or no handler */
    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\is_string($data['page'] ?? null) || !\is_string($data['handler'] ?? null)) {
            throw new BadRequestHttpException('An event needs a page and a handler.');
        }

        return new self(
            $data['page'],
            $data['handler'],
            (string) ($data['state'] ?? '{}'),
            (string) ($data['signature'] ?? ''),
            \is_array($data['event'] ?? null) ? $data['event'] : [],
        );
    }
}
