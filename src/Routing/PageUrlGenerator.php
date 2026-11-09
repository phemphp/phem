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
 * the name of the page (see PageLoader), so the Symfony URL generator finds it.
 *
 * The values are by prop or by route value: PageUrlValues gives the route value of a prop, and
 * the identifier of an entity (`['product' => $product]` for `/products/{id:product}`). The values
 * that are not route values go to the query string. The markup links with `path(ProductPage)`
 * and `url(ProductPage)`: phemc compiles them to `phem_path()` and `phem_url()`.
 */
final class PageUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly ?PageUrlValues $values = null,
    ) {
    }

    /**
     * @param string|\Closure      $page   the page: `Counter::class`, or `ScientistPage(...)`
     * @param array<string, mixed> $values the values by prop or by route value, and the query string
     * @param int                  $type   a reference type of UrlGeneratorInterface
     *
     * @return string an absolute path (`/scientists/5?tab=papers`), or the type of URL that `$type` gives
     *
     * @throws \InvalidArgumentException when the component is not a page
     */
    public function generate(string|\Closure $page, array $values = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        $name = PageName::of($page);
        $values = $this->values?->of($name, $values) ?? $values;

        try {
            return $this->urls->generate($name, $values, $type);
        } catch (RouteNotFoundException $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a page: add #[Page] to it, and run `phemc build`. A page of a package has a route only when the application imports the routes of the package (`resource: acme/ui`, `type: phem`).', $name), 0, $e);
        }
    }

    /**
     * The path of a page, as the Twig `path()` gives it: `path(ProductPage, {...}, true)` in markup
     * gives a relative path.
     *
     * @param array<string, mixed> $values the values by prop or by route value, and the query string
     */
    public function path(string|\Closure $page, array $values = [], bool $relative = false): string
    {
        return $this->generate($page, $values, $relative ? UrlGeneratorInterface::RELATIVE_PATH : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /**
     * The absolute URL of a page (`https://example.com/scientists/5`), as the Twig `url()` gives
     * it: `url(ProductPage, {...}, true)` in markup gives a URL with no scheme (`//example.com/...`).
     *
     * @param array<string, mixed> $values the values by prop or by route value, and the query string
     */
    public function url(string|\Closure $page, array $values = [], bool $schemeRelative = false): string
    {
        return $this->generate($page, $values, $schemeRelative ? UrlGeneratorInterface::NETWORK_PATH : UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** The redirect response of a RedirectTo. */
    public function redirect(RedirectTo $redirect): RedirectResponse
    {
        return new RedirectResponse($this->generate($redirect->page, $redirect->values), headers: $redirect->headers);
    }
}
