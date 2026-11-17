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

/**
 * One toast message: the detail of a `phem:toast` browser event, as the `phem-toast` controller
 * reads it (see Phem\Toasts).
 */
final class Toast
{
    /**
     * @param ToastType|null $type     a type, or null for a toast with no type (no `data-type`)
     * @param int|null       $duration the time on the screen in ms, `0` to keep the toast until its
     *                                 close button, or null for the `duration` value of the controller
     * @param string|null    $title    a short title above the message, or null for a toast with no title
     * @param string|null    $id       the id of the toast: a toast with the id of a toast on the
     *                                 screen updates it (Base UI `id`)
     * @param string|null    $action   the label of the action button of the toast (Base UI `actionProps`)
     * @param array<string, scalar|null> $data the fields of the `[data-phem-toast-data]` parts, and
     *                                 the detail of the action (Base UI `data`)
     * @param string|null    $anchor   the `id` of the element that the toast points to (Base UI
     *                                 `positionerProps.anchor`)
     * @param string|null    $viewport the `name` of the `phem-toast` element that shows the toast
     */
    public function __construct(
        public readonly string $message,
        public readonly ?ToastType $type = null,
        public readonly ?int $duration = null,
        public readonly ?string $title = null,
        public readonly ?string $id = null,
        public readonly ?string $action = null,
        public readonly array $data = [],
        public readonly ?string $anchor = null,
        public readonly ?string $viewport = null,
    ) {
    }

    /**
     * Reads the detail of a `phem:toast` event as the controller does: the detail is the message,
     * or an array with `message`, and an optional `type`, `duration`, `title`, `id`, `action`,
     * `data`, `anchor` and `viewport`. A missing type, or a type that the controller does not know,
     * is no type, and a title (an id, an action, an anchor, a viewport) that is empty or not a text
     * is no title. Data keeps only the names with a scalar value (or null).
     *
     * @return self|null null when the controller shows no toast (no message, or an empty one)
     */
    public static function fromDetail(mixed $detail): ?self
    {
        if (\is_string($detail)) {
            $detail = ['message' => $detail];
        }

        if (!\is_array($detail) || null === ($detail['message'] ?? null) || '' === $detail['message']) {
            return null;
        }

        $message = $detail['message'];
        $type = $detail['type'] ?? null;
        $duration = $detail['duration'] ?? null;
        $data = $detail['data'] ?? [];

        return new self(
            \is_string($message) || \is_int($message) || \is_float($message) ? (string) $message : json_encode($message, \JSON_THROW_ON_ERROR),
            \is_string($type) ? ToastType::tryFrom($type) : null,
            \is_int($duration) ? $duration : null,
            self::text($detail['title'] ?? null),
            self::text($detail['id'] ?? null),
            self::text($detail['action'] ?? null),
            self::data($data),
            self::text($detail['anchor'] ?? null),
            self::text($detail['viewport'] ?? null),
        );
    }

    /**
     * The data of the detail, as the controller reads it: the names with a scalar value or null.
     * A list or another value is no data.
     *
     * @return array<string, scalar|null>
     */
    private static function data(mixed $data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        return array_filter($data, static fn (mixed $value, int|string $key): bool => \is_string($key) && (null === $value || \is_scalar($value)), \ARRAY_FILTER_USE_BOTH);
    }

    /** A text of the detail: a string or a number that is not empty, or else null. */
    private static function text(mixed $value): ?string
    {
        return (\is_string($value) || \is_int($value) || \is_float($value)) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    /**
     * The detail of the `phem:toast` event: the other keys go only when they are set.
     *
     * @return array{message: string, type?: string, duration?: int, title?: string, id?: string, action?: string, data?: array<string, scalar|null>, anchor?: string, viewport?: string}
     */
    public function detail(): array
    {
        $detail = ['message' => $this->message];

        if (null !== $this->type) {
            $detail['type'] = $this->type->value;
        }

        if (null !== $this->duration) {
            $detail['duration'] = $this->duration;
        }

        if (null !== $this->title) {
            $detail['title'] = $this->title;
        }

        foreach (['id' => $this->id, 'action' => $this->action, 'anchor' => $this->anchor, 'viewport' => $this->viewport] as $key => $value) {
            if (null !== $value) {
                $detail[$key] = $value;
            }
        }

        if ([] !== $this->data) {
            $detail['data'] = $this->data;
        }

        return $detail;
    }

    public function __toString(): string
    {
        $text = null !== $this->title ? \sprintf('"%s: %s"', $this->title, $this->message) : \sprintf('"%s"', $this->message);

        return null !== $this->type ? $this->type->value.' '.$text : $text;
    }
}
