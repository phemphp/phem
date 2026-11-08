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

namespace Phem\Rendering;

use Phem\RedirectTo;

/**
 * The `mount()` of a page, or a function page, returned a Phem\RedirectTo: the render of the
 * page stops. The response of the page is a 302 (see Phem\Http\PageRenderer), and the answer of
 * an event sends the URL to the browser (see EventRenderer).
 *
 * @internal
 */
final class PageRedirect extends \RuntimeException
{
    public function __construct(
        public readonly RedirectTo $redirect,
        string $page,
    ) {
        parent::__construct(\sprintf('The page "%s" returned a RedirectTo, but this render cannot answer with a redirect (for example an error page).', $page));
    }
}
