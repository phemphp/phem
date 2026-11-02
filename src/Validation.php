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

use Phem\State\StateValue;

/**
 * The live validation of a class component: the fields that the user touched, and the first
 * error of each touched field. A #[State] property of this type turns it on:
 *
 *     #[State] public Validation $validation;
 *
 * Phem sets the property. An event touches the bound fields that the user changed. After the
 * handlers, Phem validates the #[Assert] constraints of the #[State] properties (see
 * Phem\State\StateValidator). A #[Validated] handler touches all the fields first, and it does
 * not run when there are errors (see Phem\Attribute\Validated).
 *
 * A field is a property (`email`), or a path in an array property (`address.city`). The error of
 * a constraint on the class, with no path, is the error of the empty field (`error('')`).
 *
 * The value is immutable: a change gives a new Validation. To remove the touched fields and the
 * errors (for example after a save that clears the form), set a new one:
 * `$this->validation = new Validation();`.
 *
 * The state keeps it as `{"touched": [...], "groups": [...], "errors": {...}}`.
 */
final readonly class Validation implements StateValue
{
    /**
     * @param list<string>                $touched  the fields that the user changed
     * @param non-empty-list<string>|null $groups   the groups of the last #[Validated] handler: all the fields are touched. Null before it
     * @param array<string, string>       $errors   the first error of each touched field
     * @param list<string>                $rejected the fields whose value of the browser did not fit the type, in this request (not in the state)
     * @param array<string, mixed>|null   $fields   the values of the #[State] properties that the errors are for, in this request (not in the state)
     */
    public function __construct(
        private array $touched = [],
        private ?array $groups = null,
        private array $errors = [],
        private array $rejected = [],
        private ?array $fields = null,
    ) {
    }

    /** The first error of a touched field, or null. */
    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * The first error of each touched field.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Whether the user touched a field: the field, a parent of its path (`address` for
     * `address.city`), or a field in it (`tags.0` for `tags`).
     */
    public function touched(string $field): bool
    {
        if (null !== $this->groups) {
            return true;
        }

        foreach ($this->touched as $touched) {
            if ($touched === $field || str_starts_with($field, $touched.'.') || str_starts_with($touched, $field.'.')) {
                return true;
            }
        }

        return false;
    }

    /** Whether no touched field has an error. */
    public function valid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * Touches fields: their errors show after the next validation. For example, an `onBlur`
     * handler touches a field that the user leaves with no change:
     *
     *     $touchEmail = fn () => $this->validation = $this->validation->touch('email');
     */
    public function touch(string ...$fields): self
    {
        return new self(array_values(array_unique([...$this->touched, ...$fields])), $this->groups, $this->errors, $this->rejected);
    }

    /**
     * Touches fields whose value of the browser did not fit the type: the next validation gives
     * them an error.
     *
     * @internal
     */
    public function reject(string ...$fields): self
    {
        return new self(array_values(array_unique([...$this->touched, ...$fields])), $this->groups, $this->errors, array_values(array_unique([...$this->rejected, ...$fields])));
    }

    /**
     * Touches all the fields, and keeps the groups for the next validations.
     *
     * @param list<string> $groups no group is the default group
     *
     * @internal
     */
    public function touchAll(array $groups): self
    {
        return new self([], [] !== $groups ? $groups : ['Default'], $this->errors, $this->rejected);
    }

    /**
     * The errors of a validation of the fields: only the errors of the touched fields stay.
     *
     * @param array<string, string> $errors the first error of each field
     * @param array<string, mixed>  $fields the values that the validation checked
     *
     * @internal
     */
    public function validated(array $errors, array $fields): self
    {
        return new self($this->touched, $this->groups, array_filter($errors, $this->touched(...), \ARRAY_FILTER_USE_KEY), [], $fields);
    }

    /**
     * Whether a validation must run for these values: a field is touched, and the errors are not
     * for these values, or a field was rejected.
     *
     * @param array<string, mixed> $fields
     *
     * @internal
     */
    public function needsValidation(array $fields): bool
    {
        if ([] === $this->touched && null === $this->groups) {
            return false;
        }

        return [] !== $this->rejected || $this->fields !== $fields;
    }

    /**
     * The groups of the validation: the groups of the last #[Validated] handler, or null for the
     * default group.
     *
     * @return non-empty-list<string>|null
     *
     * @internal
     */
    public function groups(): ?array
    {
        return $this->groups;
    }

    /**
     * @return list<string>
     *
     * @internal
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** @return array{touched?: list<string>, groups?: non-empty-list<string>, errors?: array<string, string>} */
    public function toState(): array
    {
        return array_filter(['touched' => $this->touched, 'groups' => $this->groups, 'errors' => $this->errors], static fn (?array $v): bool => null !== $v && [] !== $v);
    }

    /**
     * The value of toState() again. A value with another form gives an empty validation: the
     * state is signed, so only an old state has one.
     */
    public static function fromState(mixed $state): static
    {
        if (!\is_array($state)) {
            return new self();
        }

        $touched = $state['touched'] ?? [];
        $groups = \is_array($state['groups'] ?? null) ? array_values(array_filter($state['groups'], \is_string(...))) : [];
        $errors = $state['errors'] ?? [];

        return new self(
            \is_array($touched) ? array_values(array_filter($touched, \is_string(...))) : [],
            [] !== $groups ? $groups : null,
            \is_array($errors) ? array_filter($errors, \is_string(...)) : [],
        );
    }
}
