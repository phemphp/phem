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
use Phem\Handler\ObjectIdentifier;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Encodes and decodes the objects of the #[State] properties: a draft of a form, a date, or any
 * other class that is not a StateValue or a backed enum. The Serializer gives the JSON value of
 * the object, and makes the object again from it on the next event:
 *
 *     final class ProductDraft
 *     {
 *         public string $name = '';
 *         public ?int $categoryId = null;
 *     }
 *
 *     #[State, Assert\Valid] public ProductDraft $draft;
 *
 * The state keeps only the public properties of the objects, also in the nested objects: not
 * the values of the getters. A Doctrine entity, at any depth, is an error: the state keeps a copy
 * of its fields, so it gets old, and the browser can read all of them. Keep a draft class in the
 * state, and the id of the entity.
 */
final class StateObjects
{
    /** @var array<class-string, bool> whether each class is a Doctrine entity */
    private array $entityClasses = [];

    public function __construct(
        private readonly (NormalizerInterface&DenormalizerInterface)|null $serializer = null,
        private readonly ?ObjectIdentifier $entities = null,
    ) {
    }

    /**
     * The class of a #[State] property of this type, when it keeps an object with the
     * Serializer: a class or an interface that is not a StateValue and not an enum. Null for
     * another type.
     *
     * @return class-string|null
     */
    public static function classOf(?\ReflectionType $type): ?string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();
        if (!class_exists($class) && !interface_exists($class)) {
            return null;
        }

        return is_subclass_of($class, StateValue::class) || is_subclass_of($class, \UnitEnum::class) ? null : $class;
    }

    /**
     * The JSON value of an object of a #[State] property: its public properties.
     *
     * @param class-string $component the class of the component, for the errors
     *
     * @throws \LogicException when the object, or an object in it, is a Doctrine entity
     */
    public function toState(object $value, string $component, string $property): mixed
    {
        $attributes = $this->attributes($value, $component, $property, new \SplObjectStorage());

        return $this->serializer()->normalize($value, null, ['attributes' => $attributes]);
    }

    /**
     * The object of a JSON value of toState().
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $context the context of the Serializer
     *
     * @return T
     */
    public function fromState(string $class, mixed $state, array $context = []): object
    {
        $object = $this->serializer()->denormalize($state, $class, null, $context);
        if (!$object instanceof $class) {
            throw new \LogicException(\sprintf('The Serializer gives "%s" for a #[State] object of the class "%s".', get_debug_type($object), $class));
        }

        return $object;
    }

    /**
     * The public properties of an object, with the properties of its nested objects: the
     * `attributes` of the Serializer. An object with no public property (a date) has none.
     *
     * @param \SplObjectStorage<object, null> $seen the objects of the path, against a cycle
     *
     * @return array<array-key, mixed>
     */
    private function attributes(object $object, string $component, string $path, \SplObjectStorage $seen): array
    {
        if ($this->isEntity($object)) {
            throw new \LogicException(\sprintf('<%s> keeps the Doctrine entity %s in #[State] $%s. Keep a draft class in the state, and the id of the entity.', ComponentName::short($component), $object::class, $path));
        }

        if ($seen->offsetExists($object)) {
            return [];
        }

        $seen->offsetSet($object);

        $attributes = [];
        foreach (new \ReflectionObject($object)->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic()) {
                continue;
            }

            $nested = $p->isInitialized($object) ? $this->nested($p->getValue($object), $component, $path.'.'.$p->getName(), $seen) : null;
            if (null === $nested) {
                $attributes[] = $p->getName();
            } else {
                $attributes[$p->getName()] = $nested;
            }
        }

        $seen->offsetUnset($object);

        return $attributes;
    }

    /**
     * The attributes of the objects in a value of a property: an object, or a list of objects.
     * Null for a value with no object.
     *
     * @param \SplObjectStorage<object, null> $seen
     *
     * @return array<array-key, mixed>|null
     */
    private function nested(mixed $value, string $component, string $path, \SplObjectStorage $seen): ?array
    {
        if (\is_object($value)) {
            return $value instanceof \UnitEnum ? null : $this->attributes($value, $component, $path, $seen);
        }

        if (!\is_array($value)) {
            return null;
        }

        $merged = null;
        foreach ($value as $key => $item) {
            $nested = $this->nested($item, $component, $path.'.'.$key, $seen);
            if (null !== $nested) {
                $merged = array_replace_recursive($merged ?? [], $nested);
            }
        }

        return $merged;
    }

    private function isEntity(object $object): bool
    {
        return $this->entityClasses[$object::class] ??= null !== $this->entities?->identify($object);
    }

    private function serializer(): NormalizerInterface&DenormalizerInterface
    {
        return $this->serializer ?? throw new \LogicException('A #[State] object needs the Symfony Serializer: install symfony/serializer.');
    }
}
