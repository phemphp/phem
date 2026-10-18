<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\State;

use Mamu\Metadata\ComponentMetadata;
use Mamu\Rendering\Tree;

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

    /** @param array<string, mixed> $state the state that the browser sent back */
    public function restore(object $component, array $state): void
    {
        foreach ($this->metadata->stateProperties($component::class) as $p) {
            if (\array_key_exists($p->getName(), $state)) {
                $p->setValue($component, $state[$p->getName()]);
            }
        }
    }

    /** The state of the fibers of the last pass, and the route values of the page. */
    public function snapshot(Tree $tree): PageState
    {
        $fibers = [];
        foreach ($tree->mounted() as $fiber) {
            if (null !== $fiber->instance && [] !== $state = $this->export($fiber->instance)) {
                $fibers[$fiber->id] = $state;
            }
        }

        return new PageState($fibers, $tree->values);
    }
}
