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

use Phem\BrowserEventDispatcher;
use Phem\Event;
use Phem\Metadata\ComponentMetadata;
use Phem\State\BoundValues;
use Phem\State\StateAccessor;
use Phem\State\StateValidator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs an event on a tree, as React renders after a state change:
 *
 * 1. The first pass renders only the fibers on the paths from the page to the fiber of the
 *    handler, and the subtrees of the components of the bound values (see PathScope). It gives
 *    the handler and the bound properties.
 * 2. The bound values go into their #[State] properties, and the handler runs. The components
 *    with a Validation property touch the changed fields before the handler, and validate after
 *    it (see StateValidator).
 * 3. The second pass renders the dirty fibers and their subtrees, and keeps a child that cannot
 *    change (see UpdateScope). A dirty fiber is the owner of the handler (the `$this` of the
 *    handler closure, or the fiber that registered it), or a fiber whose state changed.
 *
 * A load event can have the handlers of many `<Suspense>` boundaries: the two passes are for all
 * of them, and the handlers run in their order.
 *
 * The browser also sends the stale fibers: the components whose element a push changed (see
 * Phem\Stream\Streams). They render in the two passes with their full subtree, and their HTML
 * goes to the browser (see Tree::markStale()). An event with no handler and only stale fibers is
 * a refresh. When a stale fiber is not in the page any more, its closest ancestor that rendered is
 * stale: the push changed the HTML of that ancestor.
 *
 * The render from before the handler is the shape that the browser sent (see TreeShape): the
 * tree compares the second pass with it. When the shape does not know a fiber of the paths, or
 * when the handler calls Event::refresh(), the full page renders, in the two passes.
 */
final class EventRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly BoundValues $bound = new BoundValues(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly InputsComparator $inputs = new InputsComparator(),
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly ?SkippedRenderCheck $check = null,
        private readonly StateValidator $validator = new StateValidator(),
    ) {
    }

    /**
     * @param list<string>            $handlers   the handler ids: none for an event with only bound values (`phem:live`) or a refresh, more than one for a load of many boundaries
     * @param array<array-key, mixed> $bound      the bound values: "<fiber>.<property>" => value (see BoundValues)
     * @param \Closure(): Event       $event      makes the event for a handler
     * @param BrowserEventDispatcher  $dispatcher it gets only the browser events of the handlers
     * @param list<string>            $stale      the ids of the stale fibers (see Tree::markStale())
     * @param Request|null            $request    the request that the handlers get (see Phem\Http\EventRequest)
     */
    public function run(Tree $tree, array $handlers, array $bound, BrowserEventDispatcher $dispatcher, \Closure $event, array $stale = [], ?Request $request = null): EventOutcome
    {
        $scoped = $this->firstPass($tree, $handlers, $bound, $stale);

        $closures = [];
        foreach ($handlers as $handler) {
            $closure = $tree->handler($handler);
            if (null === $closure) {
                return EventOutcome::reload();
            }

            $closures[$handler] = $closure;
        }

        $before = $this->states($tree);

        // After the first pass, as the page in the browser was: the second pass shows the new
        // values as a change.
        $touched = $this->applyBound($tree, $bound);

        // Only the handlers send browser events: not the render before them.
        $dispatcher->reset();

        $refresh = !$scoped;
        foreach ($closures as $closure) {
            $e = $event();
            $result = $this->renderer->dispatch($tree, $closure, $e, $request);
            if ($result instanceof RedirectResponse) {
                return EventOutcome::redirect($result);
            }

            $refresh = $refresh || $e->refreshes();
        }

        $this->settle($tree, $before, $touched);

        if ($refresh) {
            $this->renderer->render($tree);

            return EventOutcome::rendered();
        }

        $dirty = $this->dirty($tree, $before, $closures);
        $this->renderer->rerender($tree, self::roots($dirty), new UpdateScope(array_fill_keys(array_keys($dirty), true), $tree->shape, $this->inputs, $this->metadata));

        return EventOutcome::rendered($this->check?->check($tree, $this->state->snapshot($tree)) ?? []);
    }

    /**
     * The handler of an event, after the first pass and the bound values, as run() gets it: an
     * #[Async] handler in a worker (see Phem\Async\AsyncHandler). Null when the page does not
     * have the handler any more.
     *
     * @param array<array-key, mixed> $bound the bound values: "<fiber>.<property>" => value (see BoundValues)
     */
    public function handlerOf(Tree $tree, string $handler, array $bound): ?\Closure
    {
        $this->firstPass($tree, [$handler], $bound, []);
        $closure = $tree->handler($handler);
        // No live validation: the event had it, and the worker sends no state.
        $this->bound->apply($tree, $bound);

        return $closure;
    }

    /**
     * Sets the bound values, and touches them in the components with a Validation property (see
     * StateValidator).
     *
     * @param array<array-key, mixed> $bound
     *
     * @return array<string, true> the ids of the fibers with a bound value
     */
    private function applyBound(Tree $tree, array $bound): array
    {
        $touched = [];
        foreach ($this->bound->apply($tree, $bound) as $id => $fields) {
            $instance = $tree->fiberById($id)?->instance;
            if (null !== $instance) {
                $this->validator->touch($instance, $fields);
                $touched[$id] = true;
            }
        }

        return $touched;
    }

    /**
     * Validates the components with a Validation property whose state changed in the event, or
     * that got a bound value, after the handlers: the errors are for the new values.
     *
     * @param array<string, array<string, mixed>> $before
     * @param array<string, true>                 $touched
     */
    private function settle(Tree $tree, array $before, array $touched): void
    {
        foreach ($tree->rendered() as $fiber) {
            $instance = $fiber->instance;
            if (null === $instance || null === $this->metadata->validationProperty($instance::class)) {
                continue;
            }

            if (isset($touched[$fiber->id]) || !isset($before[$fiber->id]) || $before[$fiber->id] !== $this->state->export($instance)) {
                $this->validator->settle($instance);
            }
        }
    }

    /**
     * Renders the paths to the handlers, to the components of the bound values and to the stale
     * fibers (see the class).
     *
     * @param list<string>            $handlers
     * @param array<array-key, mixed> $bound
     * @param list<string>            $stale
     *
     * @return bool false when the full page rendered (the shape does not know a fiber of the paths)
     */
    private function firstPass(Tree $tree, array $handlers, array $bound, array $stale): bool
    {
        $owners = [];
        foreach ($bound as $key => $value) {
            if (\is_string($key)) {
                $owners[] = PathScope::fiberOf($key);
            }
        }

        // An id that the shape does not know is not in the page any more.
        $stale = array_values(array_filter(array_unique($stale), $tree->shape->has(...)));
        $tree->markStale(...$stale);
        $scope = PathScope::to($tree->shape, array_values(array_unique(array_map(PathScope::fiberOf(...), $handlers))), array_values(array_unique([...$owners, ...$stale])));
        $this->renderer->render($tree, $scope);
        $this->staleAncestors($tree, $stale);

        return null !== $scope;
    }

    /**
     * Marks as stale the closest ancestor that rendered of each stale fiber that did not render:
     * the push changed the HTML of the ancestor, and the fiber is not in it any more.
     *
     * @param list<string> $stale
     */
    private function staleAncestors(Tree $tree, array $stale): void
    {
        foreach ($stale as $id) {
            for ($current = $id; null !== $current && !self::rendered($tree, $current); $current = $tree->shape->parentOf($current)) {
            }

            if (null !== $current && $current !== $id) {
                $tree->markStale($current);
            }
        }
    }

    private static function rendered(Tree $tree, string $id): bool
    {
        $fiber = $tree->fiberById($id);

        return null !== $fiber && $fiber->pass === $tree->pass && !$fiber->kept;
    }

    /**
     * The state of each fiber with a component instance in the last pass.
     *
     * @return array<string, array<string, mixed>>
     */
    private function states(Tree $tree): array
    {
        $states = [];
        foreach ($tree->rendered() as $fiber) {
            if (null !== $fiber->instance) {
                $states[$fiber->id] = $this->state->export($fiber->instance);
            }
        }

        return $states;
    }

    /**
     * The fibers that render again: the fibers whose state changed, the owner of each handler, and
     * the stale fibers. An owner is always dirty: a handler can change a property that is not
     * state (a form).
     *
     * @param array<string, array<string, mixed>> $before
     * @param array<string, \Closure>             $closures handler id => handler
     *
     * @return array<string, Fiber>
     */
    private function dirty(Tree $tree, array $before, array $closures): array
    {
        $dirty = [];
        foreach ($tree->rendered() as $fiber) {
            if ($tree->isStale($fiber->id) || (null !== $fiber->instance && (!isset($before[$fiber->id]) || $before[$fiber->id] !== $this->state->export($fiber->instance)))) {
                $dirty[$fiber->id] = $fiber;
            }
        }

        foreach ($closures as $handler => $closure) {
            $object = new \ReflectionFunction($closure)->getClosureThis();
            $owner = (null !== $object ? $tree->fiberOf($object) : null) ?? $tree->fiberById(PathScope::fiberOf($handler));
            if (null !== $owner) {
                $dirty[$owner->id] = $owner;
            }
        }

        return $dirty;
    }

    /**
     * The dirty fibers with no dirty ancestor: each one renders with its subtree.
     *
     * @param array<string, Fiber> $dirty
     *
     * @return list<Fiber>
     */
    private static function roots(array $dirty): array
    {
        $roots = [];
        foreach ($dirty as $fiber) {
            for ($p = $fiber->parent; null !== $p; $p = $p->parent) {
                if (isset($dirty[$p->id])) {
                    continue 2;
                }
            }

            $roots[] = $fiber;
        }

        return $roots;
    }
}
