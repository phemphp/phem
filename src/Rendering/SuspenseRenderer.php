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
use Phem\Dom\Attributes;
use Phem\Html;
use Phem\Manifest\ComponentDefinition;
use Phem\State\StateAccessor;
use Phem\Suspense;
use Phem\SuspenseStatus;
use Psr\Log\LoggerInterface;

/**
 * Renders a `<Suspense>` boundary (see Phem\Suspense), by its status in the page state:
 *
 *  - pending: the fallback, in a `<phem-suspense>` element with the id of the load handler. The
 *    `phem` controller sends the load event when the page starts, or when the element comes
 *    into view (`data-phem-lazy`). The children do not render. In the render of a page, a
 *    boundary that is not `lazy` does not wait for the browser (see SuspenseLoad): the response
 *    streams it (SuspenseStream). In a mounted component of a test, its children render in it.
 *  - loaded: the children. When their render throws, the error slot replaces them, and the
 *    status is failed. Without an error slot, the exception goes on: the event fails. In a
 *    stream or in the page, the fallback stays: the browser loads it with an event, and this
 *    event fails.
 *  - failed: the error slot.
 */
final class SuspenseRenderer implements ComponentRenderer
{
    /**
     * @param \Closure(): Renderer $renderer the Renderer that renders the fiber (it uses this renderer)
     */
    public function __construct(
        private readonly \Closure $renderer,
        private readonly PropBinder $props = new PropBinder(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(ComponentDefinition $definition): bool
    {
        return Suspense::class === $definition->fqcn;
    }

    public function render(Fiber $fiber, array $slots): mixed
    {
        $suspense = $fiber->instance;
        if (!$suspense instanceof Suspense) {
            $suspense = $fiber->instance = new Suspense();
            if (null !== $fiber->restored) {
                $this->state->restore($suspense, $fiber->restored);
            }
        }

        $this->props->assign($suspense, $fiber->props, $slots);
        $renderer = ($this->renderer)();
        $load = $suspense->lazy ? SuspenseLoad::Event : $renderer->suspenseLoad();

        if ($load->loadsNow()) {
            $suspense->load();
        }

        if (SuspenseStatus::Loaded === $suspense->status) {
            if (null === $suspense->children) {
                return null;
            }

            $html = $renderer->catching($suspense->children);
            if (\is_string($html)) {
                return new Html($html);
            }

            if (null !== $suspense->error) {
                $this->logger?->error('[phem] The children of a <Suspense> threw: the error slot shows. '.$html->getMessage(), ['exception' => $html, 'fiber' => $fiber->id]);
                $suspense->status = SuspenseStatus::Failed;
            } elseif ($load->throws()) {
                throw $html;
            } else {
                $this->logger?->error('[phem] The children of a <Suspense> threw: the fallback stays, and the browser loads them with an event. '.$html->getMessage(), ['exception' => $html, 'fiber' => $fiber->id]);
                $suspense->status = SuspenseStatus::Pending;
            }
        }

        if (SuspenseStatus::Failed === $suspense->status) {
            return $suspense->error;
        }

        if ($load->defers()) {
            $renderer->defer($fiber);
        }

        $handler = $renderer->addHandler($suspense->load(...)) ?? 'static';

        return new Html(\sprintf('<phem-suspense data-phem-load="%s"%s>%s</phem-suspense>', Attributes::e($handler), $suspense->lazy ? ' data-phem-lazy' : '', (string) $suspense->fallback));
    }
}
