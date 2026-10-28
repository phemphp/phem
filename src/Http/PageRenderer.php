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
use Phem\Rendering\SuspenseLoad;
use Phem\Rendering\Tree;
use Phem\Root;
use Phem\State\StateAccessor;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use Phem\State\StateSizeLimit;
use Phem\State\UrlQuery;
use Phem\Stream\Subscription;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a page component (server-side) with its signed state. The page renders its document:
 * a component of the application with a `<Root>` (see Phem\Root). The query string of the
 * request sets the #[Url] properties, and the root element tells the browser their query (see
 * Phem\State\UrlQuery). The signed state goes to the StateCache, so the first event of the page
 * can send only its signature. A page with #[Broadcast], or with a component with #[Broadcast],
 * connects to the Mercure hub (see Phem\Stream\Subscription).
 *
 * A `<Suspense>` boundary that is not `lazy` loads in the response of the page (see
 * SuspenseLoad). The response streams the page first, with the fallbacks and with no state, then
 * a chunk for each boundary, then the state (see PageStream):
 *
 *     <template data-phem-root-state data-phem-state-value="…" data-phem-signature-value="…"></template>
 *
 * The `phem` controller starts when the browser has the full page: it sets these attributes on
 * the root element (`data-phem-streaming`). The cookie of the Mercure hub goes with the headers,
 * so it has the topics of the components of the page before the boundaries. A Turbo visit reads
 * the full response before it shows the page: it gets the fallbacks with no stream, and the
 * browser loads the boundaries with an event.
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
        private readonly ?PageStream $stream = null,
        private readonly StateSizeLimit $limit = new StateSizeLimit(),
    ) {
    }

    /**
     * The response of the page: its HTML with the `<!DOCTYPE html>`, streamed when it has
     * `<Suspense>` boundaries to load (see the class).
     *
     * @throws NotFoundHttpException when a #[Path] prop gets no value (for example no entity)
     */
    public function response(string $page, Request $request): Response
    {
        $values = array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page));

        try {
            $props = $this->paths->resolve($page, $values);
        } catch (\UnexpectedValueException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        $tree = new Tree($page, props: $props, values: $values, query: $request->query->all(), suspense: $this->suspenseLoad($request));
        $html = $this->renderer->render($tree);
        if (null === $tree->rootFiber) {
            throw new \LogicException(\sprintf('The page "%s" renders no <Root>: render it in a document component with `<Root>{{ children }}</Root>` (see "%s").', $page, Root::class));
        }

        if (null === $this->stream || !$tree->hasDeferred()) {
            return new Response("<!DOCTYPE html>\n".str_replace($tree->rootToken(), $this->stateAttributes($tree, $request), $html));
        }

        // The cookie of the hub goes with the headers, before the boundaries render.
        $this->subscription?->renew($page, $values, $request, $this->subscription->components($tree));

        return $this->stream->response($tree, $request, "<!DOCTYPE html>\n".str_replace($tree->rootToken(), 'data-phem-streaming', $html), fn (): string => $this->stateAttributes($tree, $request));
    }

    /** The state attributes of the root element, after the render of the tree. */
    private function stateAttributes(Tree $tree, Request $request): string
    {
        $subscribed = $this->subscription?->components($tree) ?? [];
        [$state, $signature] = $this->codec->encode($this->state->snapshot($tree, $subscribed)->toArray(), $tree->page, $request);
        $this->limit->check($state, $tree);
        $this->cache->save($signature, $state);

        $stream = $this->subscription?->attribute($tree->page, $tree->values, $request, $subscribed) ?? '';

        return RootElement::state($state, $signature, $this->url->of($tree), $this->cache->inlineSize()).('' !== $stream ? ' '.$stream : '');
    }

    private function suspenseLoad(Request $request): SuspenseLoad
    {
        if (null === $this->stream) {
            return SuspenseLoad::Event;
        }

        // Turbo reads the full response of a visit, a frame or a prefetch before it shows it: the
        // page shows the fallbacks at once, and the browser loads the boundaries with an event.
        return $request->headers->has('X-Turbo-Request-Id') ? SuspenseLoad::Event : SuspenseLoad::Stream;
    }
}
