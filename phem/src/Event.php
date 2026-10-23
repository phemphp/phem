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
    private bool $refresh = false;

    /**
     * @param array<string, mixed>   $form       the form fields, for `submit`: name => value, or the
     *                                           list of values of a name with more than one value
     * @param Request|null           $request    the HTTP request of the event, with the form fields
     *                                           as its POST data (`$form->handleRequest($e->request)`)
     * @param BrowserEventDispatcher $dispatcher sends browser events with the response
     *                                           (`$e->dispatcher->dispatch('toast', [...])`)
     * @param array<string, mixed>   $files      the files of the form, for `submit`: name => an
     *                                           UploadedFile, in the shape of `$request->files->all()`
     *                                           (`photos[]` gives a list). PHP removes a file that
     *                                           the handler does not move at the end of the request.
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value = null,
        public readonly ?bool $checked = null,
        public readonly ?string $key = null,
        public readonly array $form = [],
        public readonly ?Request $request = null,
        public readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
        public readonly array $files = [],
    ) {
    }

    /**
     * Renders the full page after the handler, as `router.refresh()` of Next.js. By default, only
     * the component of the handler, the components whose state changed, and their children with
     * new props render again. Call it when the handler changes data that other components read
     * (for example, a form that adds a row to a list of the page).
     */
    public function refresh(): void
    {
        $this->refresh = true;
    }

    /** Whether the handler asked for a render of the full page (see refresh()). */
    public function refreshes(): bool
    {
        return $this->refresh;
    }

    /**
     * @param array<string, mixed>        $data
     * @param BrowserEventDispatcher|null $dispatcher the `phem.browser_event_dispatcher` service, or a new one
     */
    public static function fromArray(array $data, ?Request $request = null, ?BrowserEventDispatcher $dispatcher = null): self
    {
        $fields = self::fields($data['form'] ?? null);
        $form = [];
        foreach ($fields as [$name, $value]) {
            $form[$name] = \array_key_exists($name, $form) ? [...(array) $form[$name], $value] : $value;
        }

        // FormData gives flat keys ("scientist[name]", "tags[]"). PHP makes nested arrays of
        // them, as it does for the body of a normal POST.
        parse_str(implode('&', array_map(static fn (array $f): string => rawurlencode($f[0]).'='.rawurlencode($f[1]), $fields)), $post);

        // A multipart event has the files of the form as its files.
        $files = $request?->files->all() ?? [];

        return new self(
            (string) ($data['type'] ?? ''),
            $data['value'] ?? null,
            isset($data['checked']) ? (bool) $data['checked'] : null,
            isset($data['key']) ? (string) $data['key'] : null,
            $form,
            // The browser sends the event as JSON. A copy of the request with the form fields
            // as POST data lets a Symfony form read them like a normal form submission.
            $request?->duplicate(request: $post, files: $files),
            $dispatcher ?? new BrowserEventDispatcher(),
            $files,
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
