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

use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\Tree;

/**
 * Gives a new object to each object of a #[State] property (see StateObjects) that changed in
 * place in an event, as a change of an array gives a new array.
 *
 * The bound values and the handlers change the object of the first pass: a handler of a child
 * that got it as a prop, or a handler that got it as an argument, changes the state too. After
 * them, an object whose JSON value changed becomes a copy (renew()). So the second pass sees
 * another object, and a child that gets it as a prop renders again (see
 * Phem\Rendering\InputsComparator). An object that did not change stays the same object: the
 * child is kept.
 */
final class StateCopies
{
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly StateObjects $objects = new StateObjects(),
    ) {
    }

    /**
     * The objects of the fibers that rendered, before the bound values and the handlers.
     *
     * @return array<string, array<string, object>> fiber id => property => object
     */
    public function objects(Tree $tree): array
    {
        $objects = [];
        foreach ($tree->rendered() as $fiber) {
            $instance = $fiber->instance;
            if (null === $instance) {
                continue;
            }

            foreach ($this->metadata->stateObjects($instance::class) as [$property]) {
                $value = $property->isInitialized($instance) ? $property->getValue($instance) : null;
                if (\is_object($value)) {
                    $objects[$fiber->id][$property->getName()] = $value;
                }
            }
        }

        return $objects;
    }

    /**
     * Replaces each object that changed in place with a copy. An object that a handler replaced
     * is already another object.
     *
     * @param array<string, array<string, object>> $objects the objects of objects()
     * @param array<string, array<string, mixed>>  $before  the JSON values before the event: fiber id => property => value (see StateAccessor::export())
     */
    public function renew(Tree $tree, array $objects, array $before): void
    {
        foreach ($objects as $id => $properties) {
            $instance = $tree->fiberById($id)?->instance;
            if (null === $instance) {
                continue;
            }

            foreach ($this->metadata->stateObjects($instance::class) as [$property]) {
                $name = $property->getName();
                $object = $properties[$name] ?? null;
                if (null === $object || !$property->isInitialized($instance) || $property->getValue($instance) !== $object) {
                    continue;
                }

                $state = $this->objects->toState($object, $instance::class, $name);
                if (($before[$id][$name] ?? null) !== $state) {
                    $property->setValue($instance, $this->objects->fromState($object::class, $state));
                }
            }
        }
    }
}
