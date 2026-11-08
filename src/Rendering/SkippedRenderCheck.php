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

use Phem\Attribute\Context;
use Phem\Attribute\Path;
use Phem\Attribute\Prop;
use Phem\Attribute\State;
use Phem\Binding\ComponentName;
use Phem\State\PageState;
use Phem\State\TreeShape;
use Psr\Log\LoggerInterface;

/**
 * The dev-mode check of a pure render(), as React StrictMode: after an event, renders the full
 * page again with the new state, and reports each kept fiber whose HTML is not the HTML in the
 * browser. Its render() reads data that is not a prop or state (a service, the time, a global),
 * so the event showed old data.
 *
 * A class component with a plain property that is not readonly (for example a form that a
 * handler sets) is not checked: a handler of an earlier event can have set it.
 *
 * The check doubles the work of an event: Phem uses it only with `kernel.debug`.
 */
final class SkippedRenderCheck
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param PageState $state the state after the event (see StateAccessor::snapshot())
     *
     * @return list<string> a warning for each kept fiber whose HTML changed
     */
    public function check(Tree $tree, PageState $state): array
    {
        $full = new Tree($tree->page, $state->fibers, $tree->props, $tree->values);

        try {
            $this->renderer->render($full);
        } catch (PageRedirect) {
            // The page redirects now, but the event did not render it: the event does not
            // redirect, as with no check. There is no HTML to compare.
            return [];
        }

        $now = TreeShape::of($full);

        $warnings = [];
        foreach ($tree->page() as $id => [, $fiber]) {
            if (null !== $fiber || !$now->has($id) || $now->hashOf($id) === $state->shape->hashOf($id)) {
                continue;
            }

            $fqcn = $full->fiberById($id)->fqcn ?? '';
            if (!self::isChecked($fqcn)) {
                continue;
            }

            $warning = \sprintf('<%s> did not render again after the event, but its HTML is not the same now. Its render() reads data that is not a prop or state (a service, the time, a global). Read the data in mount() or in a handler, or call $e->refresh() in the handler.', ComponentName::short($fqcn));
            $this->logger?->warning('[phem] '.$warning, ['component' => $fqcn, 'fiber' => $id]);
            $warnings[] = $warning;
        }

        return $warnings;
    }

    private static function isChecked(string $fqcn): bool
    {
        if (!class_exists($fqcn)) {
            return '' !== $fqcn;
        }

        foreach (new \ReflectionClass($fqcn)->getProperties() as $p) {
            if (!$p->isStatic() && !$p->isReadOnly() && [] === $p->getAttributes(Prop::class) && [] === $p->getAttributes(Path::class) && [] === $p->getAttributes(State::class) && [] === $p->getAttributes(Context::class)) {
                return false;
            }
        }

        return true;
    }
}
