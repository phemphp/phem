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

namespace Phem\Form;

use Phem\Attribute\MapForm;
use Phem\Event;
use Phem\Http\FormFields;
use Phem\State\Violations;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Maps the form fields of an event to the object of a #[MapForm] parameter, and validates it
 * (see Phem\Attribute\MapForm). The fields are in nested arrays, as PHP makes them from a POST.
 * The Serializer reads them as the form path of the Symfony `#[MapRequestPayload]`: the `csv`
 * format converts the strings to the types of the properties, and an empty string to null for a
 * nullable property. It always collects the denormalization errors: they are errors of the form,
 * not exceptions.
 *
 * It keeps each object for its event: Phem\Handler\MapFormMiddleware maps the form before the
 * transaction, and Phem\Argument\MapFormValueResolver gives the same object to the handler.
 */
final class FormMapper
{
    /** @var \WeakMap<Event, array<string, object>> */
    private \WeakMap $mapped;

    public function __construct(
        private readonly ?DenormalizerInterface $serializer = null,
        private readonly ?ValidatorInterface $validator = null,
    ) {
        $this->mapped = new \WeakMap();
    }

    /**
     * @param string $parameter the name of the parameter of the handler
     * @param string $class     the type of the parameter
     *
     * @throws InvalidForm when the object is not valid
     */
    public function map(Event $event, string $parameter, string $class, MapForm $attribute): object
    {
        $key = $parameter.'@'.$class;
        if (isset($this->mapped[$event][$key])) {
            return $this->mapped[$event][$key];
        }

        if (!class_exists($class)) {
            throw new \LogicException(\sprintf('The #[MapForm] parameter $%s needs the type of a class, "%s" given.', $parameter, $class));
        }

        $serializer = $this->serializer ?? throw new \LogicException('#[MapForm] needs the Symfony Serializer: install symfony/serializer.');

        // The context of the attribute can't turn off the errors: they are the errors of the form.
        $context = ['collect_denormalization_errors' => true] + $attribute->serializationContext + ['filter_bool' => true];

        $errors = [];
        try {
            $object = $serializer->denormalize($this->data($event, $attribute->name), $class, 'csv', $context);
        } catch (PartialDenormalizationException $e) {
            $object = $e->getData();
            $rejected = Violations::rejected($this->validator);
            foreach ($e->getNotNormalizableValueErrors() as $error) {
                $errors[$this->field($attribute->name, (string) $error->getPath())] = $rejected;
            }

            foreach ($e->getExtraAttributesError()?->getExtraAttributes() ?? [] as $extra) {
                $errors[$this->field($attribute->name, (string) $extra)] = $rejected;
            }
        } catch (ExtraAttributesException $e) {
            // A field that the object has no property for, with `allow_extra_attributes: false`:
            // there is no object to validate.
            $rejected = Violations::rejected($this->validator);
            foreach ($e->getExtraAttributes() as $extra) {
                $errors[$this->field($attribute->name, (string) $extra)] = $rejected;
            }

            throw [] !== $errors ? new InvalidForm($errors) : $e;
        }

        if (!$object instanceof $class) {
            throw new \LogicException(\sprintf('The Serializer gives "%s" for the #[MapForm] parameter $%s, not "%s".', get_debug_type($object), $parameter, $class));
        }

        // The constraints of the other fields too: a submit shows all the errors.
        foreach ($this->validator?->validate($object, null, [] !== $attribute->validationGroups ? $attribute->validationGroups : null) ?? [] as $violation) {
            $errors[$this->field($attribute->name, $violation->getPropertyPath())] ??= (string) $violation->getMessage();
        }

        if ([] !== $errors) {
            throw new InvalidForm($errors);
        }

        $this->mapped[$event] = [$key => $object] + ($this->mapped[$event] ?? []);

        return $object;
    }

    /**
     * The fields of the event in nested arrays, or the fields with the prefix of the name.
     *
     * @return array<array-key, mixed>
     */
    private function data(Event $event, ?string $name): array
    {
        $fields = [];
        foreach ($event->form as $field => $values) {
            foreach ((array) $values as $value) {
                $fields[] = [(string) $field, (string) $value];
            }
        }

        $data = FormFields::nested($fields);
        if (null === $name) {
            return $data;
        }

        return \is_array($data[$name] ?? null) ? $data[$name] : [];
    }

    /** The field of an error, as the Validation of the component keeps it: `dates.from`. */
    private function field(?string $name, string $path): string
    {
        $field = Violations::field($path);
        if (null === $name) {
            return $field;
        }

        return '' === $field ? $name : $name.'.'.$field;
    }
}
