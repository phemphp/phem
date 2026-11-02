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

namespace Phem\State;

use Phem\Binding\ComponentName;
use Phem\Metadata\ComponentMetadata;
use Phem\Validation;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates the #[Assert] constraints of a component with a Validation property (see
 * Phem\Validation), and keeps the first error of each touched field in it.
 *
 * An event touches the bound fields (touch()), then its handlers run, then the components whose
 * state changed validate (settle()): the errors are for the values after the handlers. A
 * #[Validated] handler validates before it runs (submit()). A validation runs only when its
 * errors are not for the current values: a submit with errors does not validate two times.
 *
 * It validates all the component, not only the changed field: a constraint on two fields (a
 * #[Assert\Callback] that compares two passwords) updates the error of the other field too. The
 * path of a violation is the path of a bound field: `address[city]` becomes `address.city`. A
 * bound value that does not fit the type gets the error "This value is not valid.".
 */
final class StateValidator
{
    private const REJECTED = 'This value is not valid.';

    public function __construct(
        private readonly ?ValidatorInterface $validator = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    /**
     * Touches the bound fields that the user changed. A component with no Validation property
     * does nothing.
     *
     * @param array<string, bool> $fields field => whether its value fit the type: a property, or a path (`address.city`)
     */
    public function touch(object $component, array $fields): void
    {
        $property = $this->metadata->validationProperty($component::class);
        if (null === $property || [] === $fields) {
            return;
        }

        $accepted = array_keys(array_filter($fields));
        $rejected = array_keys(array_filter($fields, static fn (bool $fits): bool => !$fits));
        $property->setValue($component, self::of($property, $component)->touch(...$accepted)->reject(...$rejected));
    }

    /**
     * Validates a component after the handlers of an event, when its errors are not for its
     * current values. A component with no Validation property, or with no touched field, does
     * nothing.
     */
    public function settle(object $component): void
    {
        $property = $this->metadata->validationProperty($component::class);
        if (null === $property) {
            return;
        }

        $validation = self::of($property, $component);
        if ($validation->needsValidation($this->fields($component, $property))) {
            $this->validate($component, $property, $validation);
        }
    }

    /**
     * Touches all the fields, and validates the component (see Phem\Attribute\Validated).
     *
     * @param list<string> $groups no group is the default group
     *
     * @return bool whether the component has no error
     */
    public function submit(object $component, array $groups): bool
    {
        $property = $this->metadata->validationProperty($component::class) ?? throw new \LogicException(\sprintf('A #[Validated] handler needs a #[State] property of the type Phem\Validation in <%s>.', ComponentName::short($component::class)));

        return $this->validate($component, $property, self::of($property, $component)->touchAll($groups))->valid();
    }

    private function validate(object $component, \ReflectionProperty $property, Validation $validation): Validation
    {
        $validator = $this->validator ?? throw new \LogicException('Live validation needs the Symfony Validator: install symfony/validator.');

        $errors = [];
        foreach ($validator->validate($component, null, $validation->groups()) as $violation) {
            $errors[self::field($violation->getPropertyPath())] ??= (string) $violation->getMessage();
        }

        // The translated message, as the other errors.
        if ([] !== $validation->rejected()) {
            $message = (string) $validator->validate(null, new NotNull(message: self::REJECTED))->get(0)->getMessage();
            foreach ($validation->rejected() as $field) {
                $errors[$field] = $message;
            }
        }

        $validation = $validation->validated($errors, $this->fields($component, $property));
        $property->setValue($component, $validation);

        return $validation;
    }

    /**
     * The values of the #[State] properties, but the Validation: the errors are for them.
     *
     * @return array<string, mixed>
     */
    private function fields(object $component, \ReflectionProperty $validation): array
    {
        $fields = [];
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if ($p->getName() !== $validation->getName() && $p->isInitialized($component)) {
                $fields[$p->getName()] = $p->getValue($component);
            }
        }

        return $fields;
    }

    private static function of(\ReflectionProperty $property, object $component): Validation
    {
        $validation = $property->isInitialized($component) ? $property->getValue($component) : null;

        return $validation instanceof Validation ? $validation : new Validation();
    }

    /** The field of a property path of the Symfony Validator: `items[0][name]` → `items.0.name`. */
    private static function field(string $path): string
    {
        return ltrim((string) preg_replace('/\[([^\]]*)\]/', '.$1', $path), '.');
    }
}
