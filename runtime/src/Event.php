<?php

declare(strict_types=1);

namespace Mamu;

use Symfony\Component\HttpFoundation\Request;

/**
 * The event that a handler receives (`onClick={{ handleClick }}` → `handleClick(Event $e)`).
 */
final class Event
{
    /**
     * @param array<string, mixed> $form    the form fields, for `submit`
     * @param Request|null         $request the HTTP request of the event, with the form fields
     *                                      as its POST data (`$form->handleRequest($e->request)`)
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value = null,
        public readonly ?bool $checked = null,
        public readonly ?string $key = null,
        public readonly array $form = [],
        public readonly ?Request $request = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?Request $request = null): self
    {
        $form = \is_array($data['form'] ?? null) ? $data['form'] : [];
        // FormData gives flat keys ("scientist[name]"). PHP makes nested arrays of them, as it
        // does for the body of a normal POST.
        parse_str(http_build_query($form), $post);

        return new self(
            (string) ($data['type'] ?? ''),
            $data['value'] ?? null,
            isset($data['checked']) ? (bool) $data['checked'] : null,
            isset($data['key']) ? (string) $data['key'] : null,
            $form,
            // The browser sends the event as JSON. A copy of the request with the form fields
            // as POST data lets a Symfony form read them like a normal form submission.
            $request?->duplicate(request: $post),
        );
    }
}
