<?php

declare(strict_types=1);

namespace Rephp\Controller;

use Rephp\Dom\DomEvents;
use Rephp\Http\RouteValues;
use Rephp\Http\Title;
use Rephp\Metadata\ComponentMetadata;
use Rephp\Path\PathResolver;
use Rephp\Rendering\Renderer;
use Rephp\Rendering\Tree;
use Rephp\State\StateAccessor;
use Rephp\State\StateCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * Renders a page (server-side) in the `@Rephp/page.html.twig` document.
 *
 * The route loader gives each `#[Route]` of a page component a route with the `_rephp_page`
 * (component) attribute. The route values go to the #[Path] props of the page (see RouteValues).
 */
final class PageController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly Environment $twig,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = (string) $request->attributes->get('_rephp_page');
        $values = array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page));
        try {
            $props = $this->paths->resolve($page, $values);
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
        $tree = new Tree($page, props: $props, values: $values);
        [$title, $html] = Title::extract($this->renderer->render($tree));
        [$state, $signature] = $this->codec->encode($this->state->snapshot($tree)->toArray(), $page);

        return new Response($this->twig->render('@Rephp/page.html.twig', [
            'title' => $title ?? 'rephp',
            'body' => $html,
            'page' => $page,
            'state' => $state,
            'signature' => $signature,
            'actions' => DomEvents::stimulusActions(),
        ]));
    }
}
