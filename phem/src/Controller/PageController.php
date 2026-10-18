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

namespace Phem\Controller;

use Phem\Http\PageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a page (server-side) with its document (see Phem\Root).
 *
 * The route loader gives each `#[Route]` of a page component a route with the `_phem_page`
 * (component) attribute.
 */
final class PageController
{
    public function __construct(
        private readonly PageRenderer $pages,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return new Response($this->pages->document((string) $request->attributes->get('_phem_page'), $request));
    }
}
