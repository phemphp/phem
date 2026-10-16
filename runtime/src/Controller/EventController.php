<?php

declare(strict_types=1);

namespace Rephp\Controller;

use Rephp\Event;
use Rephp\Http\EventPayload;
use Rephp\Http\TurboStreams;
use Rephp\Manifest\Manifest;
use Rephp\Path\PathResolver;
use Rephp\Rendering\Renderer;
use Rephp\Rendering\Tree;
use Rephp\State\PageState;
use Rephp\State\StateAccessor;
use Rephp\State\StateCodec;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /_rhp/event`: runs an event handler.
 *
 * The `rhp` Stimulus controller sends the signed state of the page and the handler id. The
 * server renders with that state, runs the handler, renders again, and answers with Turbo
 * Streams that morph the components that changed, plus the new state. When the handler returns
 * a RedirectResponse, the server answers with its URL, and the browser visits it.
 */
final class EventController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateCodec $codec,
        private readonly Manifest $manifest,
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly TurboStreams $streams = new TurboStreams(),
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = EventPayload::fromRequest($request);
        $page = $payload->page;
        $data = $this->codec->decode($payload->state, $payload->signature, $page);
        if (null === $data || !\in_array($page, $this->manifest->pages(), true)) {
            // The state is not valid any more (a new build, or a new secret).
            return self::reload();
        }

        // The route values of the page are in the signed state. The entities come from them again.
        $state = PageState::fromArray($data);
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
        $result = $this->renderer->dispatch($tree, $handler, Event::fromArray($payload->event, $request));
        if ($result instanceof RedirectResponse) {
            // The browser visits the URL: the page does not render again.
            return new JsonResponse(['redirect' => self::redirectUrl($result)]);
        }
        $this->renderer->render($tree);
        [$json, $signature] = $this->codec->encode($this->state->snapshot($tree)->toArray(), $page);

        return new JsonResponse(['streams' => $this->streams->updates($tree->changedFibers()), 'state' => $json, 'signature' => $signature]);
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
