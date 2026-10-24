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
     * The #[State] properties of a class component.
     *
     * @return array<string, mixed>
     */
    public function export(object $component): array
    {
        $out = [];
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if ($p->isInitialized($component)) {
                $out[$p->getName()] = $p->getValue($component);
            }
        }

        return $out;
    }

    /**
     * A backed enum is its value in the JSON of the state: it becomes a case again.
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
     * @param list<string> $subscribed the components with #[Subscribe] of the first render (see PageState)
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
