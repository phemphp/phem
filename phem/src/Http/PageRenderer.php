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

namespace Phem\Http;

use Phem\Metadata\ComponentMetadata;
use Phem\Path\PathResolver;
use Phem\Rendering\Renderer;
use Phem\Rendering\RootElement;
use Phem\Rendering\Tree;
use Phem\Root;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a page component (server-side) with its signed state. The page renders its document:
 * a component of the application with a `<Root>` (see Phem\Root).
 *
 * PageController uses it for the page routes. The browser sends the events of a page to the URL
 * of the page, and only a page route (a `#[Page]` on the component) runs them.
 */
final class PageRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
    ) {
    }

    /**
     * The HTML of the page, with the `<!DOCTYPE html>`.
     *
     * @throws NotFoundHttpException when a #[Path] prop gets no value (for example no entity)
     */
    public function document(string $page, Request $request): string
    {
        $values = array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page));
        try {
            $props = $this->paths->resolve($page, $values);
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
        $tree = new Tree($page, props: $props, values: $values);
        $html = $this->renderer->render($tree);
        if (null === $tree->rootFiber) {
            throw new \LogicException(\sprintf('The page "%s" renders no <Root>: render it in a document component with `<Root>{{ children }}</Root>` (see "%s").', $page, Root::class));
        }
        [$state, $signature] = $this->codec->encode($this->state->snapshot($tree)->toArray(), $page, $request);

        return "<!DOCTYPE html>\n".str_replace($tree->rootToken(), RootElement::state($state, $signature), $html);
    }
}
