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

namespace Mamu\Http;

use Mamu\Metadata\ComponentMetadata;
use Mamu\Path\PathResolver;
use Mamu\Rendering\Renderer;
use Mamu\Rendering\Tree;
use Mamu\State\StateAccessor;
use Mamu\State\StateCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Markup;

/**
 * Renders a page component (server-side) with its signed state, for a page template.
 *
 * PageController uses it for the page routes. A controller of the application can use it too:
 *
 *     return $this->render('page.html.twig', ['page' => $pages->render(Counter::class, $request)]);
 *
 * The browser sends the events of a page to the URL of the page, and only a page route (a
 * `#[Route]` on the component) runs them. Thus, render a page with event handlers on its
 * page route.
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
     * The route values of the request go to the #[Path] props of the page (see RouteValues).
     *
     * @throws NotFoundHttpException when a #[Path] prop gets no value (for example no entity)
     */
    public function render(string $page, Request $request): PageView
    {
        $values = array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page));
        try {
            $props = $this->paths->resolve($page, $values);
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
        $tree = new Tree($page, props: $props, values: $values);
        [$title, $html] = Title::extract($this->renderer->render($tree));
        [$state, $signature] = $this->codec->encode($this->state->snapshot($tree)->toArray(), $page, $request);

        return new PageView($page, null === $title ? null : new Markup($title, 'UTF-8'), new Markup($html, 'UTF-8'), $state, $signature);
    }
}
