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
 *
 * An event with files is `multipart/form-data`: the JSON is in the `_phem` part, and the files
 * are in the other parts (see Event::$files).
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

    /** The part of a multipart event with the JSON. */
    public const PART = '_phem';

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
    public static function fromRequest(Request $request): self
    {
        $data = json_decode(self::json($request), true, self::MAX_DEPTH);
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

    /** The JSON of the event: the body, or the `_phem` part of a multipart body. */
    public static function json(Request $request): string
    {
        if (!self::isMultipart($request)) {
            return $request->getContent();
        }
        $json = $request->request->all()[self::PART] ?? '';

        return \is_string($json) ? $json : '';
    }

    public static function isMultipart(Request $request): bool
    {
        return 'multipart/form-data' === strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));
    }
}
