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
use Phem\State\Type\BackedEnumType;
use Phem\State\Type\ObjectType;
use Phem\State\Type\StateValueType;

/**
 * Reads and writes the #[State] properties of the class components. The type of a property
 * selects how the state keeps its value (see StateType).
 */
final class StateAccessor
{
    /** @var iterable<StateType> */
    private readonly iterable $types;

    /** @var array<string, StateType|null> "<class>::<property>" => its type, or null for a value as it is */
    private array $typeOf = [];

    /**
     * @param iterable<StateType>|null $types null for the types of Phem: StateValue, backed enum and object
     */
    public function __construct(
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        ?iterable $types = null,
    ) {
        $this->types = $types ?? [new StateValueType(), new BackedEnumType(), new ObjectType()];
    }

    /**
     * The #[State] properties of a class component: their values in the JSON of the state (see
     * StateType).
     *
     * @return array<string, mixed>
     */
    public function export(object $component): array
    {
        $out = [];
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if (!$p->isInitialized($component)) {
                continue;
            }

            $value = $p->getValue($component);
            $out[$p->getName()] = $this->typeOf($p)?->toState($value, $component::class, $p->getName()) ?? $value;
        }

        return $out;
    }

    /**
     * Sets the initial StateValue on each uninitialized or null StateValue property of a new
     * component (for example a Phem\Validation): the component does not write it. The state of
     * the browser replaces it (see restore()).
     */
    public function initialize(object $component): void
    {
        foreach ($this->metadata->stateValues($component::class) as [$property, $class]) {
            if (!$property->isInitialized($component) || null === $property->getValue($component)) {
                $property->setValue($component, $class::fromState(null));
            }
        }
    }

    /**
     * Sets the values of the state that the browser sent back: a StateType makes the value of
     * its properties again (a backed enum becomes a case again).
     *
     * @param array<string, mixed> $state the state that the browser sent back
     */
    public function restore(object $component, array $state): void
    {
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if (!\array_key_exists($p->getName(), $state)) {
                continue;
            }

            $type = $p->getType();
            $value = $state[$p->getName()];
            $p->setValue($component, $type instanceof \ReflectionNamedType ? $this->typeOf($p)?->fromState($type, $value) ?? $value : $value);
        }
    }

    /** The StateType of a property: the first that supports its type. Null for a property with no single named type. */
    private function typeOf(\ReflectionProperty $property): ?StateType
    {
        $key = $property->class.'::'.$property->getName();
        if (\array_key_exists($key, $this->typeOf)) {
            return $this->typeOf[$key];
        }

        $type = $property->getType();
        $found = null;
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            foreach ($this->types as $candidate) {
                if ($candidate->supports($type)) {
                    $found = $candidate;
                    break;
                }
            }
        }

        return $this->typeOf[$key] = $found;
    }

    /**
     * The state of the fibers of the page, the route values of the page, and the shape of the
     * tree. A fiber that did not render in the last pass keeps the state that the browser sent:
     * a fiber whose state changes renders (see UpdateScope).
     *
     * @param list<string> $subscribed the components with #[Broadcast] of the first render (see PageState)
     */
    public function snapshot(Tree $tree, array $subscribed = []): PageState
    {
        $fibers = [];
        foreach ($tree->page() as $id => [, $fiber]) {
            $state = null !== $fiber ? (null !== $fiber->instance ? $this->export($fiber->instance) : []) : ($tree->restored($id) ?? []);
            if ([] !== $state) {
                $fibers[$id] = $state;
            }
        }

        return new PageState($fibers, $tree->values, TreeShape::of($tree), $subscribed);
    }
}
