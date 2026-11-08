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

namespace Phem\Rendering;

use Phem\Metadata\ComponentMetadata;
use Phem\Validation;

/**
 * Finds the validation error of a bound #[State] property, or of a path in it: the error of the
 * Validation property of the component, with the field name `<property>.<key>…` (see
 * StateValidator). The id of the error is `<fiber>-<field>-error`, with a `-` for each `.`:
 * `phem1a2b3c4d5e6f-address-city-error`. A field with other characters than letters, digits, `_`
 * and `.` gets a `-` for each run of them, and the hash of the field, so two fields never get the
 * same id: `rows['a-b'].name` → `…-rows-a-b-name-1f2e3d4c-error`. The id is unique in the page,
 * and the field and the error element get it with no id of the field.
 */
final class FieldErrors
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    /**
     * @param list<mixed> $path the keys of a path in the property
     */
    public function of(object $component, string $fiber, string $property, array $path = []): ?FieldError
    {
        // A field name has only string and int keys (see StateValidator).
        if ([] !== array_filter($path, static fn (mixed $key): bool => !\is_int($key) && !\is_string($key))) {
            return null;
        }

        try {
            $validation = $this->metadata->validationProperty($component::class);
        } catch (\LogicException) {
            // More than one Validation property: the events of the component report it (see
            // StateValidator), and phemc too (E058). The render of the page does not fail.
            return null;
        }

        if (null === $validation || !$validation->isInitialized($component)) {
            return null;
        }

        $value = $validation->getValue($component);
        if (!$value instanceof Validation) {
            return null;
        }

        $field = implode('.', [$property, ...$path]);
        $message = $value->error($field);
        if (null === $message) {
            return null;
        }

        $slug = 1 === preg_match('/^[A-Za-z0-9_.]*$/', $field)
            ? str_replace('.', '-', $field)
            : preg_replace('/[^A-Za-z0-9_]+/', '-', $field).'-'.hash('xxh32', $field);

        return new FieldError($fiber.'-'.$slug.'-error', $message);
    }
}
