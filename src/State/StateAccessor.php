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
 * Reads and writes the #[State] properties of the class components.
 */
final class StateAccessor
{
    public function __construct(private readonly ComponentMetadata $metadata = new ComponentMetadata())
    {
    }

    /**
     * The #[State] properties of a class component. A StateValue is its JSON value.
     *
     * @return array<string, mixed>
     */
    public function export(object $component): array
    {
        $out = [];
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if ($p->isInitialized($component)) {
                $value = $p->getValue($component);
                $out[$p->getName()] = $value instanceof StateValue ? $value->toState() : $value;
            }
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
     * A backed enum is its value in the JSON of the state: it becomes a case again. A
     * StateValue becomes an object again.
     *
     * @param array<string, mixed> $state the state that the browser sent back
     */
    public function restore(object $component, array $state): void
    {
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if (\array_key_exists($p->getName(), $state)) {
                $p->setValue($component, self::fromJson($p->getType(), $state[$p->getName()]));
            }
        }
    }

    private static function fromJson(?\ReflectionType $type, mixed $value): mixed
    {
        if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), StateValue::class)) {
            return $type->getName()::fromState($value);
        }

        if (!$type instanceof \ReflectionNamedType || (!\is_int($value) && !\is_string($value)) || !is_subclass_of($type->getName(), \BackedEnum::class)) {
            return $value;
        }

        return $type->getName()::from($value);
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
