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
use Phem\Rendering\RootElement;
use Phem\Rendering\Tree;
use Phem\Root;
use Phem\State\StateAccessor;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use Phem\State\UrlQuery;
use Phem\Stream\Subscription;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a page component (server-side) with its signed state. The page renders its document:
 * a component of the application with a `<Root>` (see Phem\Root). The query string of the
 * request sets the #[Url] properties, and the root element tells the browser their query (see
 * Phem\State\UrlQuery). The signed state goes to the StateCache, so the first event of the page
 * can send only its signature. A page with #[Broadcast], or with a component with #[Broadcast],
 * connects to the Mercure hub (see Phem\Stream\Subscription).
 *
 * PageController uses it for the page routes. The browser sends the events of a page to the URL
 * of the page, and only a page route (a `#[Page]` on the component) runs them.
 */
final class PageRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly UrlQuery $url = new UrlQuery(),
        private readonly StateCache $cache = new StateCache(),
        private readonly ?Subscription $subscription = null,
    ) {
    }

    /**
     * The HTML of the page, with the `<!DOCTYPE html>`.
     *
     * @throws NotFoundHttpException when a #[Path] prop gets no value (for example no entity)
     */
    public function document(string $page, Request $request): string
    {
        $values = array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page));

        try {
            $props = $this->paths->resolve($page, $values);
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        $tree = new Tree($page, props: $props, values: $values, query: $request->query->all());
        $html = $this->renderer->render($tree);
        if (null === $tree->rootFiber) {
            throw new \LogicException(\sprintf('The page "%s" renders no <Root>: render it in a document component with `<Root>{{ children }}</Root>` (see "%s").', $page, Root::class));
        }

        $subscribed = $this->subscription?->components($tree) ?? [];
        [$state, $signature] = $this->codec->encode($this->state->snapshot($tree, $subscribed)->toArray(), $page, $request);
        $this->cache->save($signature, $state);

        $stream = $this->subscription?->attribute($page, $values, $request, $subscribed) ?? '';

        return "<!DOCTYPE html>\n".str_replace($tree->rootToken(), RootElement::state($state, $signature, $this->url->of($tree), $this->cache->inlineSize()).('' !== $stream ? ' '.$stream : ''), $html);
    }
}
