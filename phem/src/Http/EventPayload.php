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

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The JSON of an event (`POST <page URL>`), from the `phem` Stimulus controller (see EventBody).
 * The page comes from the route, not from the JSON.
 */
final class EventPayload
{
    /**
     * The maximum depth of the JSON body, as json_decode() counts it. The body from the browser
     * has a depth of 5 (the body, the event, the form fields, a [name, value] pair, and the
     * value; or the body, the bound values, and a list of strings), so a deeper body does not
     * come from it.
     */
    public const MAX_DEPTH = 8;

    /**
     * @param string|null          $handler the handler id, or null for an event of `phem:live` with no handler
     * @param string               $state   the signed JSON of the page state
     * @param array<string, mixed> $event   the DOM event (type, value, checked, key, form)
     * @param array<string, mixed> $bound   the values of the bound inputs that changed: "<fiber>.<property>" => value (see BoundValues)
     */
    public function __construct(
        public readonly ?string $handler,
        public readonly string $state,
        public readonly string $signature,
        public readonly array $event,
        public readonly array $bound = [],
    ) {
    }

    /**
     * @throws BadRequestHttpException when the body is not JSON, is too deep, or has no handler
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, self::MAX_DEPTH);
        // A `phem:live` event has a null handler: it only sends the bound values.
        if (!\is_array($data) || !\array_key_exists('handler', $data) || (!\is_string($data['handler']) && (null !== $data['handler'] || !\is_array($data['bound'] ?? null)))) {
            throw new BadRequestHttpException('An event needs a handler.');
        }

        return new self(
            $data['handler'],
            (string) ($data['state'] ?? '{}'),
            (string) ($data['signature'] ?? ''),
            \is_array($data['event'] ?? null) ? $data['event'] : [],
            \is_array($data['bound'] ?? null) ? $data['bound'] : [],
        );
    }
}
