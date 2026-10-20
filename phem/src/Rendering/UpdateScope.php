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

namespace Phem\Rendering;

use Phem\Metadata\ComponentMetadata;
use Phem\State\TreeShape;

/**
 * The second pass of an event, as React renders after a state change: the dirty fibers render
 * (the owner of the handler, and the fibers whose state changed), and in their subtrees a child
 * renders only when it can change.
 *
 * A child is kept when it was in the last render, is not dirty, and gets the same props, slots
 * and caller context as in the first pass (see FiberInputs). This needs a pure render(): its
 * output depends only on the props and the state.
 *
 * A child always renders when the first pass did not get to it (its parent was not on a path),
 * and when it is a class component with no #[State] and a mount(): mount() runs each time that
 * it renders, so its output can depend on services.
 *
 * A context propagates, as in React: when a provider above a child renders in this pass and its
 * context changed (see ContextResolver::provide()), the child renders when it read a context in
 * the last render, or has such a fiber in its subtree (see TreeShape::leadsToConsumer()). So a
 * consumer under a kept fiber renders too, with the fibers on the path to it.
 *
 * @internal
 */
final class UpdateScope implements RenderScope
{
    /** @param array<string, true> $dirty the ids of the dirty fibers */
    public function __construct(
        private readonly array $dirty,
        private readonly TreeShape $before,
        private readonly InputsComparator $inputs,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    public function renders(Fiber $fiber, ?FiberInputs $previous): bool
    {
        $fiber->propagated = false;
        if (isset($this->dirty[$fiber->id])
            || !$this->before->has($fiber->id)
            || null === $previous
            || $this->metadata->mountsEachRender($fiber->fqcn)
            || !$this->inputs->same($previous, $fiber->inputs())) {
            return true;
        }

        return $fiber->propagated = $this->propagates($fiber);
    }

    /** Whether a provider above the fiber, in the fibers that render in this pass, changed its context. */
    private function propagates(Fiber $fiber): bool
    {
        if (!$this->before->leadsToConsumer($fiber->id)) {
            return false;
        }
        $pass = $fiber->parent->pass ?? null;
        for ($p = $fiber->parent; null !== $p && $p->pass === $pass && !$p->kept; $p = $p->parent) {
            if ($p->changesContext) {
                return true;
            }
        }

        return false;
    }
}
