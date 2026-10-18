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

use Phem\BrowserEventDispatcher;
use Phem\Event;
use Phem\Http\EventPayload;
use Phem\Http\RouteValues;
use Phem\Http\TurboStreams;
use Phem\Metadata\ComponentMetadata;
use Phem\Path\PathResolver;
use Phem\Rendering\Renderer;
use Phem\Rendering\Tree;
use Phem\State\PageState;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * `POST <page URL>` with the `X-Phem-Event` header: runs an event handler of the page.
 *
 * The events go to the URL of the page, so the access rules of the page apply to them too
 * (see PageLoader). The page comes from the route, not from the browser.
 *
 * The `phem` Stimulus controller sends the signed state of the page and the handler id. The
 * server renders with that state, runs the handler, renders again, and answers with Turbo
 * Streams that morph the components that changed, the new state, and the browser events of the
 * handler (see BrowserEventDispatcher). When the handler returns
 * a RedirectResponse, the server answers with its URL, and the browser visits it.
 *
 * Against CSRF, an event is JSON with the `X-Phem-Event` header (a cross-site request with them
 * needs a CORS preflight), and it comes from the same origin (`Sec-Fetch-Site`, or `Origin`).
 */
final class EventController
{
    /** The header of an event request. */
    public const HEADER = 'X-Phem-Event';

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly ?PageController $pages = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly TurboStreams $streams = new TurboStreams(),
        private readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$request->headers->has(self::HEADER)) {
            return $this->notAnEvent($request);
        }
        if (!self::isSameOrigin($request)) {
            throw new AccessDeniedHttpException('An event must come from the same origin.');
        }
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('An event is JSON (Content-Type: application/json).');
        }

        $payload = EventPayload::fromRequest($request);
        $page = (string) $request->attributes->get('_phem_page');
        $data = $this->codec->decode($payload->state, $payload->signature, $page, $request);
        if (null === $data) {
            // The state is not valid any more (a new build, a new secret, too old, or another session).
            return self::reload();
        }

        // The route values of the page are in the signed state. The entities come from them again.
        $state = PageState::fromArray($data);
        if ($state->values != array_intersect_key(RouteValues::fromRequest($request), $this->metadata->pathProps($page))) {
            // The state is for another URL of the page (`/items/3`, not `/items/4`).
            return self::reload();
        }
        try {
            $props = $this->paths->resolve($page, $state->values);
        } catch (\UnexpectedValueException) {
            // An entity of the page is not there any more: the page shows a 404 response.
            return self::reload();
        }
        $tree = new Tree($page, $state->fibers, $props, $state->values);
        $this->renderer->render($tree);
        $handler = $tree->handler($payload->handler);
        if (null === $handler) {
            // The page changed since the browser rendered it (for example after a deploy).
            return self::reload();
        }
        // Only the handler sends browser events: not the render before it.
        $this->dispatcher->reset();
        $event = Event::fromArray($payload->event, $request, $this->dispatcher);
        $result = $this->renderer->dispatch($tree, $handler, $event);
        if ($result instanceof RedirectResponse) {
            // The browser visits the URL: the page does not render again, and the browser
            // events of the handler are dropped (the new page has other listeners).
            return new JsonResponse(['redirect' => self::redirectUrl($result)]);
        }
        $this->renderer->render($tree);
        [$json, $signature] = $this->codec->encode($this->state->snapshot($tree)->toArray(), $page, $request);

        return new JsonResponse(['streams' => $this->streams->updates($tree->changedFibers()), 'state' => $json, 'signature' => $signature, 'events' => $event->dispatcher->dispatched()]);
    }

    /**
     * A POST request of the page URL that is not an event: the page renders when its route
     * accepts POST, as before the event route.
     */
    private function notAnEvent(Request $request): Response
    {
        $methods = (array) $request->attributes->get('_phem_methods', []);
        if (null === $this->pages || ([] !== $methods && !\in_array('POST', $methods, true))) {
            throw new MethodNotAllowedHttpException($methods ?: ['GET'], \sprintf('An event needs the %s header.', self::HEADER));
        }

        return ($this->pages)($request);
    }

    /**
     * Whether the browser sent the request from a page of the same origin. Without the
     * `Sec-Fetch-Site` and `Origin` headers, the client is not a browser, and a CSRF attack
     * needs one.
     */
    private static function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site) {
            return 'same-origin' === $site;
        }
        $origin = $request->headers->get('Origin');
        if (null === $origin) {
            return true;
        }
        $url = parse_url($origin);
        $host = ($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');

        return '' !== $host && 0 === strcasecmp($host, $request->getHttpHost());
    }

    /**
     * The URL of a redirect: a relative URL, or an `http:` or `https:` URL.
     *
     * The browser goes to the URL with a Turbo visit, which sets `window.location` for a URL that
     * it cannot visit. Thus a `javascript:` URL (for example from a `?next=` value) would run
     * as a script. A Location header never runs it.
     *
     * @throws \UnexpectedValueException for another scheme
     */
    private static function redirectUrl(RedirectResponse $response): string
    {
        $url = $response->getTargetUrl();
        // As the browser parses the URL: no control characters or spaces at the ends, and no tabs or line breaks.
        $normalized = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));
        if (1 === preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $m) && !\in_array(strtolower($m[1]), ['http', 'https'], true)) {
            throw new \UnexpectedValueException(\sprintf('An event handler can redirect only to a relative, http or https URL, "%s:" given.', $m[1]));
        }

        return $url;
    }

    private static function reload(): JsonResponse
    {
        return new JsonResponse(['reload' => true], 409);
    }
}
