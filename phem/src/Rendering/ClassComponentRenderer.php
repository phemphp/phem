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

use Phem\Binding\PropBinder;
use Phem\LiveCollection;
use Phem\Manifest\ComponentDefinition;
use Phem\Manifest\ComponentKind;
use Phem\Metadata\ComponentMetadata;
use Phem\State\StateAccessor;
use Phem\State\UrlQuery;

/**
 * Renders a class component. The first time a fiber renders in a request, the factory makes its
 * instance, and the props and the contexts go into it (see ContextResolver). Then the state that the browser sent back is restored;
 * without one, `mount()` initializes it, and the query string of the page request sets the
 * #[Url] properties (see Phem\Attribute\Url). Each render writes the props and the contexts again
 * and calls `render()`.
 * When a readonly prop changes, a new instance replaces the old one and keeps its state. A
 * readonly slot or markup counts as a change in each render.
 */
final class ClassComponentRenderer implements ComponentRenderer
{
    public function __construct(
        private readonly ComponentFactory $factory,
        private readonly PropBinder $props = new PropBinder(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly UrlQuery $url = new UrlQuery(),
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ContextResolver $contexts = new ContextResolver(),
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
            $this->contexts->assign($component, $fiber);
            if (null !== $fiber->restored) {
                $this->state->restore($component, $fiber->restored);
            } else {
                if (method_exists($component, 'mount')) {
                    $component->mount();
                }
                $this->url->read($component, $fiber->query);
            }
        } elseif ($this->props->changesReadonly($component, $fiber->props, $slots)) {
            // A readonly prop cannot change: a new instance gets the props, and the state of the
            // old one. As in the first render, mount() runs when the browser sent no state.
            $state = $this->state->export($component);
            $component = $fiber->instance = $this->factory->create($fiber->fqcn);
            $this->props->assign($component, $fiber->props, $slots);
            $this->contexts->assign($component, $fiber);
            if (null === $fiber->restored && method_exists($component, 'mount')) {
                $component->mount();
            }
            $this->state->restore($component, $state);
        } else {
            $this->props->assign($component, $fiber->props, $slots);
            $this->contexts->assign($component, $fiber);
            if ($this->metadata->mountsEachRender($fiber->fqcn) && method_exists($component, 'mount')) {
                // No state in the browser: mount() reads its data again in each render (for
                // example after a handler of the same event changed it).
                $component->mount();
            }
        }
        // A live collection with no value has no history (see Phem\LiveCollection).
        foreach ($this->metadata->liveCollectionProperties($fiber->fqcn) as $collection) {
            if (!$collection->isInitialized($component)) {
                $collection->setValue($component, LiveCollection::withoutHistory());
            }
        }
        if (!method_exists($component, 'render')) {
            throw new \LogicException(\sprintf('The class component "%s" has no render() method. Run `phemc build` again.', $fiber->fqcn));
        }

        return $component->render();
    }
}
