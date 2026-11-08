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

namespace Phem\Routing;

use Phem\RedirectTo;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The URL of a page, from its component: a class name, or the first-class callable of a function
 * page (`ScientistPage(...)`). The route loader gives the first route of each page an alias with
 * the name of the page (see PageLoader), so the Symfony URL generator finds it. The values that
 * are not route values go to the query string.
 */
final class PageUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param string|\Closure      $page   the page: `Counter::class`, or `ScientistPage(...)`
     * @param array<string, mixed> $values the route values, and the query string
     *
     * @return string an absolute path (`/scientists/5?tab=papers`)
     *
     * @throws \InvalidArgumentException when the component is not a page
     */
    public function generate(string|\Closure $page, array $values = []): string
    {
        $name = PageName::of($page);

        try {
            return $this->urls->generate($name, $values);
        } catch (RouteNotFoundException $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a page: add #[Page] to it, and run `phemc build`.', $name), 0, $e);
        }
    }

    /** The redirect response of a RedirectTo. */
    public function redirect(RedirectTo $redirect): RedirectResponse
    {
        return new RedirectResponse($this->generate($redirect->page, $redirect->values), headers: $redirect->headers);
    }
}
