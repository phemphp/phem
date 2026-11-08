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
 * It also shows the errors of the last #[MapForm] form of the component (see
 * Phem\Attribute\MapForm). They are not errors of the #[State] properties: each map of the form
 * replaces them, and a valid form removes them.
 *
 * A handler can add its own error to a field, for an error that no constraint finds (a name that
 * the database has already): see withError().
 *
 * The value is immutable: a change gives a new Validation. To remove the touched fields and the
 * errors (for example after a save that clears the form), set a new one:
 * `$this->validation = new Validation();`.
 *
 * The state keeps it as `{"touched": [...], "groups": [...], "errors": {...}, "form": {...}, "added": {...}}`.
 */
final readonly class Validation implements StateValue
{
    /**
     * @param list<string>                $touched  the fields that the user changed
     * @param non-empty-list<string>|null $groups   the groups of the last #[Validated] handler: all the fields are touched. Null before it
     * @param array<string, string>       $errors   the first error of each touched field
     * @param array<string, string>       $form     the first error of each field of the last #[MapForm] form
     * @param array<string, string>       $added    the error of each field that a handler added (see withError())
     * @param list<string>                $rejected the fields whose value of the browser did not fit the type, in this request (not in the state)
     * @param array<string, mixed>|null   $fields   the values of the #[State] properties that the errors are for, in this request (not in the state)
     */
    public function __construct(
        private array $touched = [],
        private ?array $groups = null,
        private array $errors = [],
        private array $form = [],
        private array $added = [],
        private array $rejected = [],
        private ?array $fields = null,
    ) {
    }

    /**
     * The error that a handler added to the field, or the first error of a touched field or of a
     * field of the form, or null.
     */
    public function error(string $field): ?string
    {
        return $this->added[$field] ?? $this->errors[$field] ?? $this->form[$field] ?? null;
    }

    /**
     * The error of each field: the errors that the handlers added, the first error of each
     * touched field, and of each field of the form.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->added + $this->errors + $this->form;
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

        return self::related($field, $this->touched);
    }

    /** Whether no field has an error: no touched field, no field of the form, and no error of a handler. */
    public function valid(): bool
    {
        return [] === $this->errors && [] === $this->form && [] === $this->added;
    }

    /**
     * Adds the error of a handler to a field: an error that no constraint finds, for example a
     * name that the database has already. The field shows it as the error of a constraint
     * (`phem:bind`, `phem:error`, error(), errors() and valid()). It goes before the error of the
     * constraints of the field:
     *
     *     $this->validation = $this->validation->withError('name', 'This name is taken.');
     *
     * The error goes when the user changes the field (or a parent or a field of its path), on the
     * next submit of a handler, or with a new Validation. The other events and validations keep
     * it. The error of the empty field (the class) goes only on a submit or with a new Validation.
     */
    public function withError(string $field, string $message): self
    {
        return new self($this->touched, $this->groups, $this->errors, $this->form, [$field => $message] + $this->added, $this->rejected, $this->fields);
    }

    /**
     * Touches fields: their errors show after the next validation. For example, an `onBlur`
     * handler touches a field that the user leaves with no change:
     *
     *     $touchEmail = fn () => $this->validation = $this->validation->touch('email');
     */
    public function touch(string ...$fields): self
    {
        return new self(array_values(array_unique([...$this->touched, ...$fields])), $this->groups, $this->errors, $this->form, $this->added, $this->rejected);
    }

    /**
     * Removes the errors of the handlers (see withError()) of the fields that the user changed:
     * the field, a parent of its path, or a field in it.
     *
     * @internal
     */
    public function changed(string ...$fields): self
    {
        $added = array_filter($this->added, static fn (string $field): bool => !self::related($field, $fields), \ARRAY_FILTER_USE_KEY);
        if ($added === $this->added) {
            return $this;
        }

        return new self($this->touched, $this->groups, $this->errors, $this->form, $added, $this->rejected, $this->fields);
    }

    /**
     * Removes the errors of the handlers before a submit: the handler finds them again.
     *
     * @internal
     */
    public function submitted(): self
    {
        if ([] === $this->added) {
            return $this;
        }

        return new self($this->touched, $this->groups, $this->errors, $this->form, [], $this->rejected, $this->fields);
    }

    /**
     * Touches fields whose value of the browser did not fit the type: the next validation gives
     * them an error.
     *
     * @internal
     */
    public function reject(string ...$fields): self
    {
        return new self(array_values(array_unique([...$this->touched, ...$fields])), $this->groups, $this->errors, $this->form, $this->added, array_values(array_unique([...$this->rejected, ...$fields])));
    }

    /**
     * Touches all the fields, and keeps the groups for the next validations. The errors of the
     * handlers go: the handler finds them again.
     *
     * @param list<string> $groups no group is the default group
     *
     * @internal
     */
    public function touchAll(array $groups): self
    {
        return new self([], [] !== $groups ? $groups : ['Default'], $this->errors, $this->form, [], $this->rejected);
    }

    /**
     * The errors of a validation of the fields: only the errors of the touched fields stay. The
     * errors of the form and of the handlers stay too.
     *
     * @param array<string, string> $errors the first error of each field
     * @param array<string, mixed>  $fields the values that the validation checked
     *
     * @internal
     */
    public function validated(array $errors, array $fields): self
    {
        return new self($this->touched, $this->groups, array_filter($errors, $this->touched(...), \ARRAY_FILTER_USE_KEY), $this->form, $this->added, [], $fields);
    }

    /**
     * The errors of a map of the form (see Phem\Attribute\MapForm): they replace the errors of
     * the last map. No error removes them.
     *
     * @param array<string, string> $errors the first error of each field
     *
     * @internal
     */
    public function mapped(array $errors): self
    {
        if ($errors === $this->form) {
            return $this;
        }

        return new self($this->touched, $this->groups, $this->errors, $errors, $this->added, $this->rejected, $this->fields);
    }

    /**
     * Whether the touched fields have an error, or a handler added one. The errors of the form do
     * not count: a #[Validated] handler validates the #[State] properties.
     *
     * @internal
     */
    public function fieldsValid(): bool
    {
        return [] === $this->errors && [] === $this->added;
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

    /** @return array{touched?: list<string>, groups?: non-empty-list<string>, errors?: array<string, string>, form?: array<string, string>, added?: array<string, string>} */
    public function toState(): array
    {
        return array_filter(['touched' => $this->touched, 'groups' => $this->groups, 'errors' => $this->errors, 'form' => $this->form, 'added' => $this->added], static fn (?array $v): bool => null !== $v && [] !== $v);
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
        $form = $state['form'] ?? [];
        $added = $state['added'] ?? [];

        return new self(
            \is_array($touched) ? array_values(array_filter($touched, \is_string(...))) : [],
            [] !== $groups ? $groups : null,
            \is_array($errors) ? array_filter($errors, \is_string(...)) : [],
            \is_array($form) ? array_filter($form, \is_string(...)) : [],
            \is_array($added) ? array_filter($added, \is_string(...)) : [],
        );
    }

    /**
     * Whether a field is one of the fields, a parent of the path of one (`address` for
     * `address.city`), or a field in one (`tags.0` for `tags`).
     *
     * @param list<string> $fields
     */
    private static function related(string $field, array $fields): bool
    {
        foreach ($fields as $other) {
            if ($other === $field || str_starts_with($field, $other.'.') || str_starts_with($other, $field.'.')) {
                return true;
            }
        }

        return false;
    }
}
