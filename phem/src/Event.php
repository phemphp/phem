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

namespace Phem;

use Symfony\Component\HttpFoundation\Request;

/**
 * The event that a handler receives (`onClick={{ handleClick }}` → `handleClick(Event $e)`).
 */
final class Event
{
    /**
     * @param array<string, mixed>   $form       the form fields, for `submit`: name => value, or the
     *                                           list of values of a name with more than one value
     * @param Request|null           $request    the HTTP request of the event, with the form fields
     *                                           as its POST data (`$form->handleRequest($e->request)`)
     * @param BrowserEventDispatcher $dispatcher sends browser events with the response
     *                                           (`$e->dispatcher->dispatch('toast', [...])`)
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value = null,
        public readonly ?bool $checked = null,
        public readonly ?string $key = null,
        public readonly array $form = [],
        public readonly ?Request $request = null,
        public readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?Request $request = null): self
    {
        $fields = self::fields($data['form'] ?? null);
        $form = [];
        foreach ($fields as [$name, $value]) {
            $form[$name] = \array_key_exists($name, $form) ? [...(array) $form[$name], $value] : $value;
        }
        // FormData gives flat keys ("scientist[name]", "tags[]"). PHP makes nested arrays of
        // them, as it does for the body of a normal POST.
        parse_str(implode('&', array_map(static fn (array $f): string => rawurlencode($f[0]).'='.rawurlencode($f[1]), $fields)), $post);

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

    /**
     * The form fields in their order. The browser sends [name, value] pairs, so a name can have
     * more than one value (`<select multiple>`, checkboxes). A hash of name => value also works.
     *
     * @return list<array{string, string}>
     */
    private static function fields(mixed $form): array
    {
        if (!\is_array($form)) {
            return [];
        }
        $fields = [];
        foreach ($form as $name => $value) {
            if (\is_int($name) && \is_array($value) && \is_string($value[0] ?? null) && \is_scalar($value[1] ?? null)) {
                $fields[] = [$value[0], (string) $value[1]];
            } elseif (\is_string($name) && \is_scalar($value)) {
                $fields[] = [$name, (string) $value];
            }
        }

        return $fields;
    }
}
