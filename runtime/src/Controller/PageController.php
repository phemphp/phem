<?php

declare(strict_types=1);

namespace Rephp\Controller;

use Rephp\Rendering\PropBinder;
use Rephp\Rendering\Renderer;
use Rephp\Rendering\Tree;
use Rephp\StateCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * Renders a page (server-side) in the `@Rephp/page.html.twig` document.
 *
 * The route loader gives each `#[Route]` of a page component a route with the `_rephp_page`
 * (component) attribute. The route values go to the #[Path] props of the page.
 */
final class PageController
{
    /** The DOM events that the `rhp` controller listens to. */
    public const ACTIONS = ['click', 'dblclick', 'input', 'change', 'submit', 'keydown', 'keyup', 'focusin', 'focusout'];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly Environment $twig,
        private readonly PropBinder $props = new PropBinder(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = (string) $request->attributes->get('_rephp_page');
        try {
            $props = $this->props->pathValues($page, (array) $request->attributes->get('_route_params', []));
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }
        $tree = new Tree($page, props: $props);
        $html = $this->renderer->render($tree);
        [$state, $signature] = $this->codec->encode($this->renderer->exportState($tree), $page);

        // React 19 style: a <title> anywhere in the page goes into <head>.
        $title = 'rephp';
        if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
            $title = trim($m[1]);
            $html = str_replace($m[0], '', $html);
        }

        return new Response($this->twig->render('@Rephp/page.html.twig', [
            'title' => $title,
            'body' => $html,
            'page' => $page,
            'state' => $state,
            'signature' => $signature,
            'actions' => implode(' ', array_map(static fn (string $a): string => $a.'->rhp#dispatch', self::ACTIONS)),
        ]));
    }
}
