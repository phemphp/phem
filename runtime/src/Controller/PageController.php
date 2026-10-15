<?php

declare(strict_types=1);

namespace Rephp\Controller;

use Rephp\Rendering\Renderer;
use Rephp\Rendering\Tree;
use Rephp\StateCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Renders a page (server-side) in the `@Rephp/page.html.twig` document.
 *
 * The route loader gives each `#[Page]` a route with the `_rephp_page` (component) and
 * `_rephp_route` (path) attributes.
 */
final class PageController
{
    /** The DOM events that the `rhp` controller listens to. */
    public const ACTIONS = ['click', 'dblclick', 'input', 'change', 'submit', 'keydown', 'keyup', 'focusin', 'focusout'];

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $route = (string) $request->attributes->get('_rephp_route');
        $tree = new Tree((string) $request->attributes->get('_rephp_page'));
        $html = $this->renderer->render($tree);
        [$state, $signature] = $this->codec->encode($this->renderer->exportState($tree), $route);

        // React 19 style: a <title> anywhere in the page goes into <head>.
        $title = 'rephp';
        if (preg_match('#<title>(.*?)</title>#s', $html, $m)) {
            $title = trim($m[1]);
            $html = str_replace($m[0], '', $html);
        }

        return new Response($this->twig->render('@Rephp/page.html.twig', [
            'title' => $title,
            'body' => $html,
            'route' => $route,
            'state' => $state,
            'signature' => $signature,
            'actions' => implode(' ', array_map(static fn (string $a): string => $a.'->rhp#dispatch', self::ACTIONS)),
        ]));
    }
}
