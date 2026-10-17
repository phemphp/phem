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

namespace Mamu\Controller;

use Mamu\Http\PageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Renders a page (server-side) in the page template of the application.
 *
 * The route loader gives each `#[Route]` of a page component a route with the `_mamu_page`
 * (component) attribute. The template is the `mamu.page_template` option, or the
 * `_mamu_template` default of the route. It gets the page as the `page` variable (a PageView).
 */
final class PageController
{
    /** The route default that sets the template of a page. */
    public const TEMPLATE = '_mamu_template';

    public function __construct(
        private readonly PageRenderer $pages,
        private readonly Environment $twig,
        private readonly string $template = '@Mamu/page.html.twig',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = $this->pages->render((string) $request->attributes->get('_mamu_page'), $request);
        $template = $request->attributes->get(self::TEMPLATE);

        return new Response($this->twig->render(\is_string($template) && '' !== $template ? $template : $this->template, ['page' => $page]));
    }
}
