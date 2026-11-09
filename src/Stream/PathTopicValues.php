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

namespace Phem\Stream;

use Phem\Binding\ComponentName;
use Phem\Handler\ObjectIdentifier;
use Phem\Metadata\ComponentMetadata;
use Phem\Path\EntityFinder;

/**
 * The values of the topic of one URL of a page (see Topics), from its #[Path] props.
 *
 * An entity prop is the identifier of its entity, not the route value: `/orders/{order}`,
 * `/orders/{id:order}` and `/o/{slug:order}` give the same topic for the same order, and the
 * publisher gives the entity (`toPage(OrderPage::class, ['order' => $order])`). A scalar prop
 * is its route value.
 */
final class PathTopicValues
{
    private readonly NameValues $names;

    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ?ObjectIdentifier $objects = null,
        private readonly ?EntityFinder $entities = null,
    ) {
        $this->names = new NameValues($objects);
    }

    /**
     * The values as strings (see NameValues): an entity in an identifier (a derived identity) is
     * its identifier too.
     *
     * @param array<array-key, mixed>             $values
     * @param \Closure(string, mixed): \Throwable $invalid the exception for a value with no string
     *
     * @return array<array-key, mixed>
     */
    public function normalize(array $values, \Closure $invalid): array
    {
        return $this->names->normalize($values, $invalid);
    }

    /**
     * The values of a page render: the identifier of each resolved entity, else the route value
     * (also for an object of a custom PathValueConverter, which has no identifier).
     *
     * @param array<string, mixed> $values the route values of the page
     * @param array<string, mixed> $props  the resolved props of the page (see Phem\Path\PathResolver)
     *
     * @return array<string, mixed>
     */
    public function ofRender(array $values, array $props): array
    {
        foreach ($values as $name => $value) {
            $prop = $props[$name] ?? null;
            if (\is_object($prop) && null !== $id = $this->objects?->identify($prop)) {
                $values[$name] = $id;
            }
        }

        return $values;
    }

    /**
     * The props with an entity that has an identifier.
     *
     * @param array<string, mixed> $props the resolved props of the page
     *
     * @return list<string>
     */
    public function entities(array $props): array
    {
        $names = [];
        foreach ($props as $name => $prop) {
            if (\is_object($prop) && null !== $this->objects?->identify($prop)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The values of the publisher (see Streams::toPage()): an entity is its identifier.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException when an entity prop gets a value that is not an object, or an entity with no identifier yet
     */
    public function ofPublish(string $page, array $values): array
    {
        $types = $this->metadata->pathProps($page);

        foreach ($values as $name => $value) {
            if (\is_object($value)) {
                $id = $this->objects?->identify($value);
                if ([] === $id) {
                    throw new \LogicException(\sprintf('The #[Path] prop "%s" of "%s" gets a %s with no identifier yet: refresh its page after the flush.', $name, $page, ComponentName::short($value::class)));
                }

                $values[$name] = $id ?? $value;

                continue;
            }

            $type = $types[$name] ?? null;
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && null !== $value && true === $this->entities?->supports($type->getName())) {
                throw new \LogicException(\sprintf('The #[Path] prop "%s" of "%s" is an entity: give the %s (or a reference: $entityManager->getReference(%s::class, …)), not %s.', $name, $page, ComponentName::short($type->getName()), ComponentName::short($type->getName()), get_debug_type($value)));
            }
        }

        return $values;
    }
}
