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

use Phem\Http\FormFields;

/**
 * The event that a handler receives (`onClick={{ handleClick }}` → `handleClick(Event $e)`).
 *
 * The event is an argument of the handler as the others (see Phem\Argument\CallableArguments):
 * the handler declares it at any position, or not at all. A handler that needs the request
 * declares `Request $request`: it has the form fields as its POST data (see
 * Phem\Http\EventRequest).
 */
final class Event
{
    private bool $refresh = false;

    /**
     * @param array<string, mixed>   $form       the form fields, for `submit`: name => value, or the
     *                                           list of values of a name with more than one value
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
     * @param array<string, mixed>        $files      the files of a multipart event (`$request->files->all()`)
     */
    public static function fromArray(array $data, ?BrowserEventDispatcher $dispatcher = null, array $files = []): self
    {
        $form = [];
        foreach (FormFields::of($data['form'] ?? null) as [$name, $value]) {
            $form[$name] = \array_key_exists($name, $form) ? [...(array) $form[$name], $value] : $value;
        }

        return new self(
            (string) ($data['type'] ?? ''),
            $data['value'] ?? null,
            isset($data['checked']) ? (bool) $data['checked'] : null,
            isset($data['key']) ? (string) $data['key'] : null,
            $form,
            $dispatcher ?? new BrowserEventDispatcher(),
            $files,
        );
    }
}
