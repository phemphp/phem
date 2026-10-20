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
use Phem\ErrorBoundary;
use Phem\Html;
use Phem\Manifest\ComponentDefinition;
use Psr\Log\LoggerInterface;

/**
 * Renders an `<ErrorBoundary>` (see Phem\ErrorBoundary): the children, or the fallback when
 * their render throws. The boundary has no state, so each render (each pass of each event) tries
 * the children again.
 */
final class ErrorBoundaryRenderer implements ComponentRenderer
{
    /**
     * @param \Closure(): Renderer $renderer the Renderer that renders the fiber (it uses this renderer)
     */
    public function __construct(
        private readonly \Closure $renderer,
        private readonly PropBinder $props = new PropBinder(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(ComponentDefinition $definition): bool
    {
        return ErrorBoundary::class === $definition->fqcn;
    }

    public function render(Fiber $fiber, array $slots): mixed
    {
        $boundary = $fiber->instance;
        if (!$boundary instanceof ErrorBoundary) {
            $boundary = $fiber->instance = new ErrorBoundary();
        }
        $this->props->assign($boundary, $fiber->props, $slots);
        if (null === $boundary->children) {
            return null;
        }

        $html = ($this->renderer)()->catching($boundary->children);
        if (\is_string($html)) {
            return new Html($html);
        }
        $this->logger?->error('[phem] The children of an <ErrorBoundary> threw: the fallback shows. '.$html->getMessage(), ['exception' => $html, 'fiber' => $fiber->id]);

        return $boundary->fallback;
    }
}
