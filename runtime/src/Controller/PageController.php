<?php

declare(strict_types=1);

namespace Rephp\Controller;

use Rephp\Http\PageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Renders a page (server-side) in the page template of the application.
 *
 * The route loader gives each `#[Route]` of a page component a route with the `_rephp_page`
 * (component) attribute. The template is the `rephp.page_template` option, or the
 * `_rephp_template` default of the route. It gets the page as the `page` variable (a PageView).
 */
final class PageController
{
    /** The route default that sets the template of a page. */
    public const TEMPLATE = '_rephp_template';

    public function __construct(
        private readonly PageRenderer $pages,
        private readonly Environment $twig,
        private readonly string $template = '@Rephp/page.html.twig',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = $this->pages->render((string) $request->attributes->get('_rephp_page'), $request);
        $template = $request->attributes->get(self::TEMPLATE);

        return new Response($this->twig->render(\is_string($template) && '' !== $template ? $template : $this->template, ['page' => $page]));
    }
}
