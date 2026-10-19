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
use Phem\Rendering\Fiber;
use Phem\Rendering\Renderer;
use Phem\Rendering\RootElement;
use Phem\Rendering\Tree;
use Phem\State\BoundValues;
use Phem\State\PageState;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Phem\State\UrlQuery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
    /** @var array<string, array<string, mixed>> fiber id => state, after a JSON round trip */
    private array $state = [];
    private ?string $redirect = null;
    private string $url = '/';

    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        private readonly Renderer $renderer,
        private readonly StateAccessor $accessor,
        private readonly StateCodec $codec,
        private readonly string $component,
        private readonly array $props = [],
        private readonly ?RequestStack $requests = null,
        private readonly ?ResetInterface $resetter = null,
        private readonly ?SessionInterface $session = null,
        private readonly BrowserEventDispatcher $dispatcher = new BrowserEventDispatcher(),
        private readonly BoundValues $bound = new BoundValues(),
        private readonly UrlQuery $query = new UrlQuery(),
    ) {
        $this->tree = new Tree($this->component, props: $this->props);
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

    public function dispatch(?string $handler, array $event, array $bound = []): EventResult
    {
        $this->resetter?->reset();
        $this->redirect = null;

        return $this->within($this->request('POST'), function (Request $request) use ($handler, $event, $bound): EventResult {
            $tree = new Tree($this->component, $this->state, $this->props);
            $this->renderer->render($tree);
            $closure = null !== $handler ? $tree->handler($handler) : null;
            if (null !== $handler && null === $closure) {
                return EventResult::reload();
            }
            // As the browser sends them: JSON values.
            $this->bound->apply($tree, json_decode(json_encode((object) $bound, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR));
            $this->dispatcher->reset();
            if (null !== $closure) {
                $e = Event::fromArray($event, $request, $this->dispatcher);
                $result = $this->renderer->dispatch($tree, $closure, $e);
                if ($result instanceof RedirectResponse) {
                    return EventResult::redirect($this->redirect = $result->getTargetUrl());
                }
            }
            $this->renderer->render($tree);
            $this->tree = $tree;
            $this->save($request);
            $this->url = UrlQuery::merge($this->url, $this->query->of($tree)['values']);

            $updates = [];
            foreach ($tree->changedFibers() as $fiber) {
                $updates[$fiber->id] = $fiber->html;
            }

            // As the browser gets them: JSON values.
            return EventResult::updates($updates, json_decode(json_encode($this->dispatcher->dispatched(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR));
        });
    }

    public function state(?string $fiber = null): array
    {
        return $this->state[$fiber ?? Fiber::idOf('/'.$this->component.'#0')] ?? [];
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
        $this->state = PageState::fromArray($data)->fibers;

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
}
