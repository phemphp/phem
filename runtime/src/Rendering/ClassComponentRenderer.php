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

namespace Mamu\Rendering;

use Mamu\Binding\PropBinder;
use Mamu\Manifest\ComponentDefinition;
use Mamu\Manifest\ComponentKind;
use Mamu\State\StateAccessor;

/**
 * Renders a class component. The first time a fiber renders in a request, the factory makes its
 * instance, and the props go into it. Then the state that the browser sent back is restored;
 * without one, `mount()` initializes it. Each render writes the props again and calls `render()`.
 * When a readonly prop changes, a new instance replaces the old one and keeps its state.
 */
final class ClassComponentRenderer implements ComponentRenderer
{
    public function __construct(
        private readonly ComponentFactory $factory,
        private readonly PropBinder $props = new PropBinder(),
        private readonly StateAccessor $state = new StateAccessor(),
    ) {
    }

    public function supports(ComponentDefinition $definition): bool
    {
        return ComponentKind::ClassComponent === $definition->kind;
    }

    public function render(Fiber $fiber, array $slots): mixed
    {
        $component = $fiber->instance;
        if (null === $component) {
            $component = $fiber->instance = $this->factory->create($fiber->fqcn);
            $this->props->assign($component, $fiber->props, $slots);
            if (null !== $fiber->restored) {
                $this->state->restore($component, $fiber->restored);
            } elseif (method_exists($component, 'mount')) {
                $component->mount();
            }
        } elseif ($this->props->changesReadonly($component, $fiber->props, $slots)) {
            // A readonly prop cannot change: a new instance gets the props, and the state of the old one.
            $state = $this->state->export($component);
            $component = $fiber->instance = $this->factory->create($fiber->fqcn);
            $this->props->assign($component, $fiber->props, $slots);
            $this->state->restore($component, $state);
        } else {
            $this->props->assign($component, $fiber->props, $slots);
        }
        if (!method_exists($component, 'render')) {
            throw new \LogicException(\sprintf('The class component "%s" has no render() method. Run `mamuc build` again.', $fiber->fqcn));
        }

        return $component->render();
    }
}
