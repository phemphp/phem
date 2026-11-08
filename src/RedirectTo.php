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

namespace Phem;

/**
 * A redirect to a page: the page component (a class name, or the first-class callable of a
 * function page) and its route values. The values that are not route values go to the query
 * string, and `_fragment` sets the fragment (see Phem\Routing\PageUrlGenerator). The headers go
 * with the response of the redirect.
 *
 * An event handler returns it: the browser visits the page.
 *
 *     public function delete(): RedirectTo
 *     {
 *         $this->scientists->remove($this->scientist);
 *
 *         return new RedirectTo(ScientistsPage::class, ['page' => 2]);
 *     }
 *
 *     return new RedirectTo(ScientistPage(...), ['id' => $scientist->id]);
 *
 * The `mount()` of a page, or a function page, returns it too: the GET of the page ends with a
 * 302, and the page does not render.
 *
 *     #[Page('/v/{token}')]
 *     function VerifyLink(string $token, VerifyLinks $links): RedirectTo
 *     {
 *         return $links->verify($token)
 *             ? new RedirectTo(AccountPage(...), headers: ['Referrer-Policy' => 'no-referrer'])
 *             : new RedirectTo(LinkVoidPage(...));
 *     }
 */
final class RedirectTo
{
    /**
     * @param string|\Closure                    $page    the page: `CounterPage::class`, or `ScientistPage(...)` for a function page
     * @param array<string, mixed>               $values  the route values, and the query string
     * @param array<string, string|list<string>> $headers the headers of the response
     */
    public function __construct(
        public readonly string|\Closure $page,
        public readonly array $values = [],
        public readonly array $headers = [],
    ) {
    }
}
