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
     * @param int|null $duration the time on the screen in ms, `0` to keep the toast until its close
     *                           button, or null for the `duration` value of the controller
     */
    public function __construct(
        public readonly string $message,
        public readonly ToastType $type = ToastType::Info,
        public readonly ?int $duration = null,
    ) {
    }

    /**
     * Reads the detail of a `phem:toast` event as the controller does: the detail is the message,
     * or an array with `message`, and an optional `type` and `duration`. A type that the
     * controller does not know shows as `info`.
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

        return new self(
            \is_string($message) || \is_int($message) || \is_float($message) ? (string) $message : json_encode($message, \JSON_THROW_ON_ERROR),
            \is_string($type) ? ToastType::tryFrom($type) ?? ToastType::Info : ToastType::Info,
            \is_int($duration) ? $duration : null,
        );
    }

    /**
     * The detail of the `phem:toast` event: the duration goes only when it is set.
     *
     * @return array{message: string, type: string, duration?: int}
     */
    public function detail(): array
    {
        $detail = ['message' => $this->message, 'type' => $this->type->value];

        if (null !== $this->duration) {
            $detail['duration'] = $this->duration;
        }

        return $detail;
    }

    public function __toString(): string
    {
        return \sprintf('%s "%s"', $this->type->value, $this->message);
    }
}
