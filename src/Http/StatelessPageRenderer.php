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

namespace Phem\Http;

use Phem\Metadata\ComponentMetadata;
use Phem\Path\PathResolver;
use Phem\Rendering\Renderer;
use Phem\Rendering\SuspenseLoad;
use Phem\Rendering\Tree;

/**
 * Renders a page component as static HTML: with no state, no stream and no events. Its
 * `<Suspense>` boundaries render in the page, with no fallback. The page can render a document
 * with a `<Root>` (the `phem` controller starts with no state) or a plain HTML document. The
 * error pages use it (see ErrorPageRenderer); PageRenderer renders the pages of the routes.
 */
final class StatelessPageRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
    ) {
    }

    /**
     * The HTML of the page, with the `<!DOCTYPE html>`.
     *
     * @param array<string, mixed> $values the values of the parameters of the page, by name: the
     *                                     page gets the ones that it declares
     */
    public function html(string $page, array $values): string
    {
        $values = array_intersect_key($values, $this->metadata->pathProps($page));
        $tree = new Tree($page, props: $this->paths->resolve($page, $values), suspense: SuspenseLoad::Inline);
        $html = $this->renderer->render($tree);

        // The root element has the token of the state attributes: the page has no state.
        return "<!DOCTYPE html>\n".(null !== $tree->rootFiber ? str_replace(' '.$tree->rootToken(), '', $html) : $html);
    }
}
