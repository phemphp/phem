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

namespace Phem\Attribute;

/**
 * Marks a function as the page of an HTTP error: the document that the browser gets when a
 * request fails (a 404, a 500). The page usually renders the document of the application (see
 * Phem\Root); a plain HTML document works too:
 *
 *     #[ErrorPage]
 *     function ErrorPage(int $status)
 *     {
 *         return (
 *             <Document>
 *                 <Document:title>Error {{ status }}</Document:title>
 *                 ...
 *             </Document>
 *         );
 *     }
 *
 * With no status, the page is for all the statuses. With one or more statuses, it is only for
 * them. `path` gives a section of the application its own pages: the request path is `path` or
 * starts with `path/`. The longest path wins, then a page with the status wins over a page for
 * all the statuses (see Phem\Http\ErrorPages):
 *
 *     #[ErrorPage(404)]
 *     function NotFoundPage() { ... }
 *
 *     #[ErrorPage([401, 403], path: '/admin')]
 *     function AdminDeniedPage() { ... }
 *
 * The function needs no `#[Component]`, and it is not a tag. Its parameters get the arguments of
 * a function page (the Request, the services, see Phem\Argument\CallableArguments) and, by name,
 * `int $status` and `$exception`: the `FlattenException` of Symfony, or the exception itself for
 * the type of an exception (`\Throwable $exception`). An error page has no state and no
 * handlers: its URL is the URL that failed (see Phem\Http\ErrorPageRenderer). Only the
 * application has error pages, and ErrorPagesWarmer checks the attributes when the container
 * builds.
 */
#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_FUNCTION)]
final class ErrorPage
{
    /**
     * @param int|list<int>|null $status the HTTP statuses (400 to 599), or null for all of them
     * @param string             $path   the path of the section of the application: `/` for all
     */
    public function __construct(
        public readonly int|array|null $status = null,
        public readonly string $path = '/',
    ) {
    }
}
