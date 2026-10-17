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

/**
 * Renders a page (server-side) in its document (see Mamu\Document).
 *
 * The route loader gives each `#[Route]` of a page component a route with the `_mamu_page`
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
        return new Response($this->pages->document((string) $request->attributes->get('_mamu_page'), $request));
    }
}
