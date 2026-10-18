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
use Phem\State\PageState;
use Phem\State\StateAccessor;
use Phem\State\StateCodec;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Renders a component with its props, in the process of the test, and runs its events as the
 * EventController does: each event makes the components again, restores the signed state,
 * renders, runs the handler, renders again, and morphs the components that changed.
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
    ) {
        $this->tree = new Tree($this->component, props: $this->props);
        $this->html = $this->within($this->request('GET'), function (Request $request): string {
            $html = $this->renderer->render($this->tree);

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

    public function dispatch(string $handler, array $event): EventResult
    {
        $this->resetter?->reset();
        $this->redirect = null;

        return $this->within($this->request('POST'), function (Request $request) use ($handler, $event): EventResult {
            $tree = new Tree($this->component, $this->state, $this->props);
            $this->renderer->render($tree);
            $closure = $tree->handler($handler);
            if (null === $closure) {
                return EventResult::reload();
            }
            $this->dispatcher->reset();
            $e = Event::fromArray($event, $request, $this->dispatcher);
            $result = $this->renderer->dispatch($tree, $closure, $e);
            if ($result instanceof RedirectResponse) {
                return EventResult::redirect($this->redirect = $result->getTargetUrl());
            }
            $this->renderer->render($tree);
            $this->tree = $tree;
            $this->save($request);

            $updates = [];
            foreach ($tree->changedFibers() as $fiber) {
                $updates[$fiber->id] = $fiber->html;
            }

            // As the browser gets them: JSON values.
            return EventResult::updates($updates, json_decode(json_encode($e->dispatcher->dispatched(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR));
        });
    }

    public function state(?string $fiber = null): array
    {
        return $this->state[$fiber ?? Fiber::idOf('/'.$this->component.'#0')] ?? [];
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
        return null !== $this->tree->rootFiber ? str_replace($this->tree->rootToken(), RootElement::state(...$signed), $html) : $html;
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
