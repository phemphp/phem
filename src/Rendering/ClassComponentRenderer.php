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

use Phem\Attribute\Page;
use Phem\Binding\PropBinder;
use Phem\Manifest\ComponentDefinition;
use Phem\Manifest\ComponentKind;
use Phem\Metadata\ComponentMetadata;
use Phem\RedirectTo;
use Phem\State\StateAccessor;
use Phem\State\UrlQuery;

/**
 * Renders a class component. The first time a fiber renders in a request, the factory makes its
 * instance, and the props, the contexts and the feeds go into it (see ContextResolver and
 * FeedBinder). A Validation property gets a new Validation. Then the state that the browser sent back is restored;
 * without one, `mount()` initializes it, and the query string of the page request sets the
 * #[Url] properties (see Phem\Attribute\Url). The `mount()` of a page can return a
 * Phem\RedirectTo: the page does not render (see PageRedirect). Each render writes the props and
 * the contexts again and calls `render()`.
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
        private readonly FeedBinder $feeds = new FeedBinder(),
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
            $this->feeds->bind($component);
            $this->state->initialize($component);

            if (null !== $fiber->restored) {
                $this->state->restore($component, $fiber->restored);
            } else {
                $this->mount($component, $fiber);
                $this->url->read($component, $fiber->query);
            }

            $this->feeds->load($component, $fiber);
        } elseif ($this->props->changesReadonly($component, $fiber->props, $slots)) {
            // A readonly prop cannot change: a new instance gets the props, and the state of the
            // old one. As in the first render, mount() runs when the browser sent no state.
            $state = $this->state->export($component);
            $component = $fiber->instance = $this->factory->create($fiber->fqcn);
            $this->props->assign($component, $fiber->props, $slots);
            $this->contexts->assign($component, $fiber);
            $this->feeds->bind($component);
            $this->state->initialize($component);

            if (null === $fiber->restored) {
                $this->mount($component, $fiber);
            }

            $this->state->restore($component, $state);
            $this->feeds->load($component, $fiber);
        } else {
            $this->props->assign($component, $fiber->props, $slots);
            $this->contexts->assign($component, $fiber);

            if ($this->metadata->mountsEachRender($fiber->fqcn)) {
                // No state in the browser: mount() reads its data again in each render (for
                // example after a handler of the same event changed it).
                $this->mount($component, $fiber);
            }
        }

        if (!method_exists($component, 'render')) {
            throw new \LogicException(\sprintf('The class component "%s" has no render() method. Run `phemc build` again.', $fiber->fqcn));
        }

        return $component->render();
    }

    /**
     * Calls `mount()`. The `mount()` of a page can return a RedirectTo: the render of the page
     * stops (see PageRedirect).
     *
     * @throws PageRedirect when the `mount()` of the page returns a RedirectTo
     */
    private function mount(object $component, Fiber $fiber): void
    {
        if (!method_exists($component, 'mount')) {
            return;
        }

        $result = $component->mount();
        if (!$result instanceof RedirectTo) {
            return;
        }

        if ([] === new \ReflectionClass($component)->getAttributes(Page::class)) {
            throw new \LogicException(\sprintf('The mount() of "%s" returns a RedirectTo, but the component is not a page. Only the mount() of a page can redirect: return the RedirectTo from an event handler, or from the mount() of the page.', $fiber->fqcn));
        }

        throw new PageRedirect($result, $fiber->fqcn);
    }
}
