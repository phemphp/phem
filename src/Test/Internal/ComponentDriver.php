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

namespace Phem\Test\Internal;

use Phem\BrowserEventDispatcher;
use Phem\Event;
use Phem\Http\EventRequest;
use Phem\Http\FeedStreams;
use Phem\Rendering\EventRenderer;
use Phem\Rendering\FeedItem;
use Phem\Rendering\Fiber;
use Phem\Rendering\Renderer;
use Phem\Rendering\RootElement;
use Phem\Rendering\SuspenseLoad;
use Phem\Rendering\Tree;
use Phem\State\PageState;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Phem\State\UrlQuery;
use Phem\Upload\ResolvedUploads;
use Phem\Upload\UploadController;
use Phem\Upload\UploadResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Renders a component with its props, in the process of the test, and runs its events as the
 * EventController does: each event makes the components again, restores the signed state,
 * renders, sets the bound values, runs the handler, renders again, and morphs the components that changed.
 *
 * There is no HTTP request: the access rules of a page do not apply. The services are reset
 * before each event, as between two requests.
 *
 * @internal
 */
final class ComponentDriver implements Driver
{
    private Tree $tree;
    private string $html;
    /** The state of the last render, after a JSON round trip, as the browser keeps it. */
    private PageState $state;
    private ?string $redirect = null;
    private string $url = '/';

    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateAccessor $accessor,
        private readonly StateCodec $codec,
        private readonly EventRenderer $events,
        private readonly string $component,
        private readonly array $props = [],
        private readonly ?RequestStack $requests = null,
        private readonly ?ResetInterface $resetter = null,
        private readonly ?SessionInterface $session = null,
        private readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
        private readonly UrlQuery $query = new UrlQuery(),
        private readonly FeedStreams $feeds = new FeedStreams(),
        private readonly ?UploadController $uploads = null,
        private readonly ?UploadResolver $resolver = null,
    ) {
        $this->state = new PageState();
        // As the end of the stream of a page: the `<Suspense>` boundaries that are not lazy show their children.
        $this->tree = new Tree($this->component, props: $this->props, suspense: SuspenseLoad::Inline);

        $this->html = $this->within($this->request('GET'), function (Request $request): string {
            $html = $this->renderer->render($this->tree);
            $this->url = UrlQuery::merge($this->url, $this->query->of($this->tree)['values']);

            return $this->withState($html, $this->save($request));
        });
    }

    public function html(): string
    {
        return $this->html;
    }

    public function isDocument(): bool
    {
        return false;
    }

    public function dispatch(array $handlers, array $event, array $bound = [], array $stale = [], ?array $item = null, array $feeds = []): EventResult
    {
        $this->resetter?->reset();
        $this->redirect = null;

        $request = $this->request('POST');
        $files = $event['files'] ?? [];
        unset($event['files']);
        $request->files->replace(TestFiles::of(TestUploads::others($files)));

        // As the browser: the files of `data-phem-upload` go up before the event (see TestUploads).
        $uploads = new ResolvedUploads($request->files->all());
        if ([] !== TestUploads::of($files) && null !== $this->uploads && null !== $this->resolver) {
            $parts = TestUploads::send(TestUploads::of($files), $this->upload(...));
            if (\is_int($parts)) {
                return EventResult::error($parts);
            }

            $request->request->set(UploadResolver::PART, $parts);

            try {
                $uploads = $this->resolver->resolve($request);
            } catch (HttpExceptionInterface $e) {
                return EventResult::error($e->getStatusCode());
            }
        }

        // As the server reads them (see Phem\Http\EventPayload).
        $items = array_values(array_filter([
            null !== $item ? FeedItem::of($item['feed'], $item['token']) : null,
            ...array_map(static fn (array $f): ?FeedItem => FeedItem::of($f['feed'], $f['token'], \in_array($f['op'], ['append', 'prepend', 'update'], true) ? $f['op'] : null), $feeds),
        ]));

        try {
            return $this->run($request, $handlers, $event, $bound, $stale, $items, $uploads);
        } finally {
            $uploads->release();
        }
    }

    /**
     * Runs the event with its files.
     *
     * @param list<string>         $handlers
     * @param array<string, mixed> $event
     * @param array<string, mixed> $bound
     * @param list<string>         $stale
     * @param list<FeedItem>       $items
     */
    private function run(Request $request, array $handlers, array $event, array $bound, array $stale, array $items, ResolvedUploads $uploads): EventResult
    {
        return $this->within($request, function (Request $request) use ($handlers, $event, $bound, $stale, $items, $uploads): EventResult {
            $tree = new Tree($this->component, $this->state->fibers, $this->props, shape: $this->state->shape, feedItems: $items);

            // As the browser sends them: JSON values.
            $bound = json_decode(json_encode((object) $bound, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            $outcome = $this->events->run($tree, $handlers, $bound, $this->dispatcher, fn (): Event => Event::fromArray($event, $this->dispatcher, $uploads->files), $stale, EventRequest::of($request, $event));
            if ($outcome->reload) {
                return EventResult::reload();
            }

            if (null !== $outcome->redirect) {
                return EventResult::redirect($this->redirect = $outcome->redirect->getTargetUrl());
            }

            $this->tree = $tree;
            $this->save($request);
            $this->url = UrlQuery::merge($this->url, $this->query->of($tree)['values']);

            $updates = [];
            foreach ($tree->changedFibers() as $fiber) {
                $updates[$fiber->id] = $fiber->wrapped();
            }

            // As the browser gets them: JSON values.
            return EventResult::updates($updates, json_decode(json_encode($this->dispatcher->dispatched(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR), clearFiles: $outcome->clearFiles, streams: $this->feeds->of($tree));
        });
    }

    public function state(?string $fiber = null): array
    {
        return $this->state->fibers[$fiber ?? Fiber::idOf('/'.$this->component.'#0')] ?? [];
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): int
    {
        return 200;
    }

    public function redirect(): ?string
    {
        return $this->redirect;
    }

    /**
     * Keeps the state of the last render as the browser does: signed JSON. A value that is not
     * a JSON value fails here, not in the browser.
     *
     * @return array{0: string, 1: string} the JSON and its signature
     */
    private function save(Request $request): array
    {
        $signed = $this->codec->encode($this->accessor->snapshot($this->tree)->toArray(), $this->component, $request);
        $data = $this->codec->decode($signed[0], $signed[1], $this->component, $request) ?? throw new \LogicException('The state of the component cannot be signed.');
        $this->state = PageState::fromArray($data);

        return $signed;
    }

    /**
     * A mounted page (or document) renders a `<Root>`: its state attributes are there.
     *
     * @param array{0: string, 1: string} $signed
     */
    private function withState(string $html, array $signed): string
    {
        return null !== $this->tree->rootFiber ? str_replace($this->tree->rootToken(), RootElement::state(...$signed, query: $this->query->of($this->tree)), $html) : $html;
    }

    private function request(string $method): Request
    {
        $request = Request::create('/', $method, server: 'POST' === $method ? ['CONTENT_TYPE' => 'application/json'] : []);

        // A mounted page is the page of the request, as in the kernel: its #[IsGranted] is a rule
        // of the page, and the rules of a page don't apply here (see Phem\Handler\IsGrantedMiddleware).
        if (class_exists($this->component) && [] !== new \ReflectionClass($this->component)->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            $request->attributes->set('_phem_page', $this->component);
        }

        if (null !== $this->session) {
            $request->setSession($this->session);
        }

        return $request;
    }

    /**
     * Runs the callback with the request in the request stack, as in a request of the kernel.
     *
     * @template T
     *
     * @param \Closure(Request): T $callback
     *
     * @return T
     */
    private function within(Request $request, \Closure $callback): mixed
    {
        $this->requests?->push($request);

        try {
            return $callback($request);
        } finally {
            $this->requests?->pop();
        }
    }

    /** Sends a request of an upload to the UploadController, as the HTTP kernel does. */
    private function upload(Request $request): Response
    {
        try {
            return ($this->uploads ?? throw new \LogicException('No upload controller.'))($request);
        } catch (HttpExceptionInterface $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        }
    }
}
