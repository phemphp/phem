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
     */
    public function __construct(
        public readonly string $message,
        public readonly ?ToastType $type = ToastType::Info,
        public readonly ?int $duration = null,
        public readonly ?string $title = null,
    ) {
    }

    /**
     * Reads the detail of a `phem:toast` event as the controller does: the detail is the message,
     * or an array with `message`, and an optional `type`, `duration` and `title`. A missing type, or a type
     * that the controller does not know, is no type, and a title that is empty or not a text is no title.
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
        $title = $detail['title'] ?? null;

        return new self(
            \is_string($message) || \is_int($message) || \is_float($message) ? (string) $message : json_encode($message, \JSON_THROW_ON_ERROR),
            \is_string($type) ? ToastType::tryFrom($type) : null,
            \is_int($duration) ? $duration : null,
            (\is_string($title) || \is_int($title) || \is_float($title)) && '' !== trim((string) $title) ? trim((string) $title) : null,
        );
    }

    /**
     * The detail of the `phem:toast` event: the type, the duration and the title go only when they
     * are set.
     *
     * @return array{message: string, type?: string, duration?: int, title?: string}
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

        return $detail;
    }

    public function __toString(): string
    {
        $text = null !== $this->title ? \sprintf('"%s: %s"', $this->title, $this->message) : \sprintf('"%s"', $this->message);

        return null !== $this->type ? $this->type->value.' '.$text : $text;
    }
}
