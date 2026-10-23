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

    /** The maximum number of stale fibers of an event. */
    public const MAX_STALE = 100;

    /**
     * @param string|null          $handler the handler id, or null for an event of `phem:live` with no handler
     * @param string|null          $state   the signed JSON of the page state, or null when the event sends only the signature (see StateCache)
     * @param array<string, mixed> $event   the DOM event (type, value, checked, key, form)
     * @param array<string, mixed> $bound   the values of the bound inputs that changed: "<fiber>.<property>" => value (see BoundValues)
     * @param list<string>         $stale   the fibers whose element a push changed (see Phem\Rendering\Tree::markStale())
     */
    public function __construct(
        public readonly ?string $handler,
        public readonly ?string $state,
        public readonly string $signature,
        public readonly array $event,
        public readonly array $bound = [],
        public readonly array $stale = [],
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
            \is_string($data['state'] ?? null) ? $data['state'] : null,
            (string) ($data['signature'] ?? ''),
            \is_array($data['event'] ?? null) ? $data['event'] : [],
            \is_array($data['bound'] ?? null) ? $data['bound'] : [],
            self::stale($data['stale'] ?? null),
        );
    }

    /**
     * The fiber ids of the `stale` list, at most MAX_STALE: a longer list does not come from the
     * browser. A value that is not a fiber id is ignored.
     *
     * @return list<string>
     */
    private static function stale(mixed $stale): array
    {
        if (!\is_array($stale)) {
            return [];
        }

        if (\count($stale) > self::MAX_STALE) {
            throw new BadRequestHttpException(\sprintf('An event has %d stale components at most.', self::MAX_STALE));
        }

        return array_values(array_filter($stale, static fn (mixed $id): bool => \is_string($id) && 1 === preg_match('/^phem[0-9a-f]{12}$/', $id)));
    }
}
