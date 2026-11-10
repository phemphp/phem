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
    /**
     * The name of the multipart parts of the files of a `drop` or a `paste` (`_phem_files[]`).
     * The handler gets them as a list in `$files`.
     */
    public const FILES = '_phem_files';

    /** The events whose files are a list in `$files` (see FILES). */
    private const DATA_TRANSFERS = ['drop', 'paste'];

    private bool $refresh = false;
    private bool $clearFiles = false;

    /**
     * @param array<string, mixed>    $form       the fields of the form of the element (a submit, a change
     *                                            or a click of an element in a form): name => value,
     *                                            or the list of values of a name with more than one value.
     *                                            A #[MapForm] parameter gets them as a typed object
     *                                            (see Phem\Attribute\MapForm)
     * @param BrowserEventDispatcher  $dispatcher sends browser events with the response
     *                                            (`$e->dispatcher->dispatch('saved', [...])`).
     *                                            For a toast, call toast()
     * @param array<array-key, mixed> $files      the files of the form, for `submit`: name => an
     *                                            UploadedFile, in the shape of `$request->files->all()`
     *                                            (`photos[]` gives a list). For `drop` and `paste`:
     *                                            the list of the dropped or pasted files. PHP removes
     *                                            a file that the handler does not move at the end
     *                                            of the request.
     * @param string|null             $text       the text of a `drop` or a `paste` (`text/plain`), or
     *                                            an empty string. A paste fires before the browser
     *                                            puts the text in the field: `$value` is the old value
     * @param mixed                   $detail     the detail of a `call` (`onCall`): the JSON value that
     *                                            the JS code puts in the `phem:call` event. The browser
     *                                            makes it, so the handler must check it, as `$value`
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value = null,
        public readonly ?bool $checked = null,
        public readonly ?string $key = null,
        public readonly array $form = [],
        public readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
        public readonly array $files = [],
        public readonly ?string $text = null,
        public readonly mixed $detail = null,
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
     * Shows a toast message after the morph (see Phem\Toasts):
     *
     *     $e->toast('Saved');
     *     $e->toast('No stock', ToastType::Error);
     *
     * @param int|null $duration the time on the screen in ms (up to Toasts::MAX_DURATION), `0` to
     *                           keep the toast until its close button, or null for the
     *                           `duration` value of the controller
     *
     * @throws \InvalidArgumentException for an empty message or a duration out of range
     */
    public function toast(string $message, ToastType $type = ToastType::Info, ?int $duration = null): void
    {
        new Toasts($this->dispatcher)->show($message, $type, $duration);
    }

    /**
     * Empties the file inputs that sent their files with the submit, in the browser, after the
     * morph. By default, the morph keeps the files of an input, and the next submit sends them
     * again. Call it when the handler took the files (for example, it stored them). A handler
     * that shows a validation error does not call it: the user does not select the files again.
     */
    public function clearFiles(): void
    {
        $this->clearFiles = true;
    }

    /** Whether the handler took the files of the form (see clearFiles()). */
    public function clearsFiles(): bool
    {
        return $this->clearFiles;
    }

    /**
     * @param array<string, mixed>        $data
     * @param BrowserEventDispatcher|null $dispatcher the `phem.browser_event_dispatcher` service, or a new one
     * @param array<string, mixed>        $files      the files of a multipart event (`$request->files->all()`)
     */
    public static function fromArray(array $data, ?BrowserEventDispatcher $dispatcher = null, array $files = []): self
    {
        // Append each value to the list of its name: a copy of the list for each value takes
        // quadratic time for a body with many fields of the same name.
        $values = [];
        foreach (FormFields::of($data['form'] ?? null) as [$name, $value]) {
            $values[$name][] = $value;
        }

        $form = array_map(static fn (array $list): string|array => 1 === \count($list) ? $list[0] : $list, $values);
        $type = (string) ($data['type'] ?? '');

        // The files of a drop or a paste have no form field: they are a list.
        if (\in_array($type, self::DATA_TRANSFERS, true)) {
            $files = \is_array($files[self::FILES] ?? null) ? array_values($files[self::FILES]) : [];
        }

        return new self(
            $type,
            $data['value'] ?? null,
            isset($data['checked']) ? (bool) $data['checked'] : null,
            isset($data['key']) ? (string) $data['key'] : null,
            $form,
            $dispatcher ?? new BrowserEventDispatcher(),
            $files,
            isset($data['text']) ? (string) $data['text'] : null,
            $data['detail'] ?? null,
        );
    }
}
