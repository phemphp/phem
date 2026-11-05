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
use Phem\Dom\UrlScheme;
use Phem\Event;
use Phem\Http\EventBody;
use Phem\Http\EventPayload;
use Phem\Http\EventReplay;
use Phem\Http\EventRequest;
use Phem\Http\RouteValues;
use Phem\Http\TurboStreams;
use Phem\Metadata\ComponentMetadata;
use Phem\Path\PathResolver;
use Phem\Rendering\EventRenderer;
use Phem\Rendering\Fiber;
use Phem\Rendering\Tree;
use Phem\State\PageState;
use Phem\State\StateAccessor;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use Phem\State\StateJson;
use Phem\State\StateSizeLimit;
use Phem\State\UrlQuery;
use Phem\Stream\SenderToken;
use Phem\Stream\Subscription;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * `POST <page URL>` with the `X-Phem-Event` header: runs an event handler of the page.
 *
 * The events go to the URL of the page, so the access rules of the page apply to them too
 * (see PageLoader). The page comes from the route, not from the browser.
 *
 * The `phem` Stimulus controller sends the signature of the state of the page, the handler id
 * (or the ids of the `<Suspense>` boundaries that load together), and the values of the bound inputs that changed (see BoundValues). The server finds the state
 * of the signature in the StateCache. When the cache does not have it, the server answers 409
 * with `{"state": "required"}` before the handler runs, and the browser sends the event again
 * with its full state. An event with files always sends its full state. The server renders the path to
 * the handler with that state, sets the bound values, runs the handler, renders the components
 * that can change (see EventRenderer), and answers with Turbo Streams that morph the components
 * that changed, the entries of the state that changed and the new signature (see StateJson),
 * the query of the #[Url] properties (see UrlQuery), and the
 * browser events of the handler (see BrowserEventDispatcher). The request has the EventReplay of
 * the event: an #[Async] handler sends it to a worker (see Phem\Async\AsyncMiddleware). The browser
 * also sends the components whose element a push changed: they render again (see EventRenderer).
 * When the handler returns a Phem\RedirectTo or a RedirectResponse, the server answers with its
 * URL, and the browser visits it. When the render of a refresh (an event with no handler, after
 * a push) throws a 401, 403, 404 or 410 HTTP exception (an entity went away), the server answers
 * 409 with `{"reload": true}`: the browser loads the page again, and shows the error page.
 *
 * An event with the files of a form is `multipart/form-data` (see EventBody). The handler
 * gets them as UploadedFile objects (see Event::$files). PHP removes a file that the handler does
 * not move at the end of the request.
 *
 * Against CSRF, an event has the `X-Phem-Event` header (a cross-site request with it needs a CORS
 * preflight), it is JSON, or multipart with files, and it comes from the same origin
 * (`Sec-Fetch-Site`, or `Origin`).
 *
 * Against a denial of service, the JSON has a maximum size (`phem.max_event_size`, also after
 * the server unzips a gzip body, see EventBody) and a maximum depth (see EventPayload), and the
 * files of an event have a maximum size together (`phem.max_upload_size`). The state is in the
 * JSON, so the size limit is also a limit for the state.
 */
final class EventController
{
    /** The header of an event request. */
    public const HEADER = 'X-Phem-Event';

    /** The statuses of a render that load the page again after a refresh: the page cannot render for this user now. */
    private const GONE = [401, 403, 404, 410];

    public function __construct(
        private readonly EventRenderer $events,
        private readonly StateCodec $codec,
        private readonly ?PageController $pages = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
        private readonly PathResolver $paths = new PathResolver(),
        private readonly StateAccessor $state = new StateAccessor(),
        private readonly TurboStreams $streams = new TurboStreams(),
        private readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
        private readonly EventBody $body = new EventBody(),
        private readonly UrlQuery $url = new UrlQuery(),
        private readonly int $maxUploadSize = 8388608,
        private readonly StateCache $cache = new StateCache(),
        private readonly ?Subscription $subscription = null,
        private readonly SenderToken $sender = new SenderToken(),
        private readonly StateSizeLimit $limit = new StateSizeLimit(),
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

        $multipart = EventBody::isMultipart($request);
        if ('json' !== $request->getContentTypeFormat() && !$multipart) {
            throw new UnsupportedMediaTypeHttpException('An event is JSON (Content-Type: application/json), or multipart/form-data with files.');
        }

        if ($multipart && [] === $request->request->all() && [] === $request->files->all()) {
            // PHP drops all the parts of a body larger than its post_max_size.
            throw new HttpException(413, 'An event with files is larger than the post_max_size option of PHP.');
        }

        $body = $this->body->json($request);
        if ($multipart && $this->filesAreTooLarge($request->files->all())) {
            throw new HttpException(413, \sprintf('The files of an event can have a maximum of %d bytes together (the phem.max_upload_size option).', $this->maxUploadSize));
        }

        $payload = EventPayload::fromJson($body);
        $json = $payload->state;
        if (null === $json) {
            if ($multipart) {
                // A miss would upload the files two times.
                throw new BadRequestHttpException('An event with files sends its state.');
            }

            if ($this->codec->isTooOld($payload->signature)) {
                return self::reload();
            }

            $json = $this->cache->find($payload->signature);
            if (null === $json) {
                // The browser sends the event again with its state. The handler did not run.
                return self::json(['state' => 'required'], 409);
            }
        }

        $page = (string) $request->attributes->get('_phem_page');
        $data = $this->codec->decode($json, $payload->signature, $page, $request);
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

        $tree = new Tree($page, $state->fibers, $props, $state->values, shape: $state->shape);
        if (1 === \count($payload->handlers)) {
            // Only an event with one handler: a load event of many boundaries is not #[Async].
            $request->attributes->set(EventReplay::ATTRIBUTE, new EventReplay($page, $json, $payload->bound, $payload->handlers[0], $payload->event));
        }

        // The refreshes of the handlers get the token of the event (see SenderToken).
        $this->sender->startEvent();

        try {
            $outcome = $this->events->run($tree, $payload->handlers, $payload->bound, $this->dispatcher, fn (): Event => Event::fromArray($payload->event, $this->dispatcher, $request->files->all()), $payload->stale, EventRequest::of($request, $payload->event));
        } catch (HttpExceptionInterface $e) {
            // A refresh (an event with no handler, after a push) of a page that this user cannot
            // see now: the browser loads the page again, and shows the error page of the server.
            if ([] !== $payload->handlers || !\in_array($e->getStatusCode(), self::GONE, true)) {
                throw $e;
            }

            return self::reload();
        } finally {
            $this->sender->endEvent();
        }

        if ($outcome->reload) {
            // The page changed since the browser rendered it (for example after a deploy).
            return self::reload();
        }

        if (null !== $outcome->redirect) {
            // The browser visits the URL: the page does not render again, and the browser
            // events of the handler are dropped (the new page has other listeners).
            return self::json(['redirect' => self::redirectUrl($outcome->redirect)]);
        }

        // A new access token for the hub, before the old one expires (see Subscription).
        if (null !== $this->subscription) {
            $this->subscription->renew($page, $state->values, $request, $this->subscription->rendered($tree, $state->subscribed));
        }

        [$next, $signature] = $this->codec->encode($this->state->snapshot($tree, $state->subscribed)->toArray(), $page, $request);
        $this->limit->check($next, $tree);

        // The next event can send only the signature (see StateCache).
        $this->cache->save($signature, $next);

        $query = $this->url->of($tree);
        // Only the entries of the state that changed, or a patch of them: the browser has the state
        // of this event.
        $changes = (object) StateJson::changes($json, $next);
        $data = ['streams' => $this->streams->updates($tree->changedFibers(), $tree->shape), 'changes' => $changes, 'signature' => $signature, 'query' => ['values' => (object) $query['values'], 'push' => $query['push']], 'events' => $this->dispatcher->dispatched()];

        // The refreshes of this event skip the fibers that it rendered, in this browser.
        if (null !== $token = $this->sender->issued()) {
            $data['sender'] = $token;
            $data['rendered'] = array_map(static fn (Fiber $f): string => $f->id, $tree->rendered());
        }

        if ($outcome->clearFiles) {
            // The browser empties the file inputs that sent files (see Event::clearFiles()).
            $data['clearFiles'] = true;
        }

        if ([] !== $outcome->warnings) {
            // The dev-mode check (see SkippedRenderCheck): the phem controller logs them.
            $data['warnings'] = $outcome->warnings;
        }

        return self::json($data);
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
     * Whether the files are larger than `phem.max_upload_size` together. A file with an upload
     * error (larger than `upload_max_filesize` of PHP) has no content: the handler gets it, so a
     * form can show the error (`#[Assert\File]`).
     *
     * @param array<mixed> $files the files of the request, in their PHP shape
     */
    private function filesAreTooLarge(array $files): bool
    {
        if (0 === $this->maxUploadSize) {
            return false;
        }

        $size = 0;
        array_walk_recursive($files, static function (mixed $file) use (&$size): void {
            if ($file instanceof UploadedFile && \UPLOAD_ERR_OK === $file->getError()) {
                $size += (int) $file->getSize();
            }
        });

        return $size > $this->maxUploadSize;
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
        $scheme = UrlScheme::of($url);
        if (null !== $scheme && !\in_array($scheme, ['http', 'https'], true)) {
            throw new \UnexpectedValueException(\sprintf('An event handler can redirect only to a relative, http or https URL, "%s:" given.', $scheme));
        }

        return $url;
    }

    private static function reload(): JsonResponse
    {
        return self::json(['reload' => true], 409);
    }

    /**
     * An answer to the `phem` controller, which reads it with fetch() and JSON.parse() only.
     *
     * The JSON has no HEX escapes (the defaults of JsonResponse): they make a JSON safe in an HTML
     * `<script>`, and this JSON is never in HTML. With them, each `"` of the signed state and each
     * `<` of the streams take 6 bytes (`\u0022`), so the response is about two times larger. The
     * `nosniff` header stops a browser that reads the response as HTML, so a `<script>` in a
     * string of the JSON cannot run.
     *
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status, ['X-Content-Type-Options' => 'nosniff']);
        $response->setEncodingOptions(\JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        return $response->setData($data);
    }
}
