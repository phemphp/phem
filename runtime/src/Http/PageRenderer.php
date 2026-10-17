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

use Mamu\Document;
use Mamu\Metadata\ComponentMetadata;
use Mamu\Path\PathResolver;
use Mamu\Rendering\MarkupResolver;
use Mamu\Rendering\Renderer;
use Mamu\Rendering\Tree;
use Mamu\State\StateAccessor;
use Mamu\State\StateCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Markup;

/**
 * Renders a page component (server-side) with its signed state, in its document (see
 * Mamu\Document).
 *
 * PageController uses it for the page routes. A controller of the application can render the
 * page in a Twig template too:
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
        private readonly MarkupResolver $markup,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
    ) {
    }

    /**
     * The HTML of the page in its document: a page is a class that extends the document of the
     * application (see Mamu\Document).
     *
     * @throws NotFoundHttpException when a #[Path] prop gets no value (for example no entity)
     */
    public function document(string $page, Request $request): string
    {
        [$view, $tree] = $this->view($page, $request);
        $document = $tree->root()?->instance;
        if (!$document instanceof Document) {
            throw new \LogicException(\sprintf('The page "%s" must be a class that implements "%s": make it extend the document of the application.', $page, Document::class));
        }

        return "<!DOCTYPE html>\n".$this->markup->html($document->document($view));
    }

    /**
     * The route values of the request go to the #[Path] props of the page (see RouteValues).
     *
     * @throws NotFoundHttpException when a #[Path] prop gets no value (for example no entity)
     */
    public function render(string $page, Request $request): PageView
    {
        return $this->view($page, $request)[0];
    }

    /** @return array{PageView, Tree} */
    private function view(string $page, Request $request): array
    {
        $values = array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page));
        try {
            $props = $this->paths->resolve($page, $values);
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
        $tree = new Tree($page, props: $props, values: $values);
        $html = $this->renderer->render($tree);
        [$state, $signature] = $this->codec->encode($this->state->snapshot($tree)->toArray(), $page, $request);

        return [new PageView($page, new Markup($html, 'UTF-8'), $state, $signature), $tree];
    }
}
