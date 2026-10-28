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

use Dom\HTMLDocument;
use Phem\Http\EventBody;
use Phem\Rendering\Fiber;
use Phem\Rendering\KeptRuns;
use Phem\State\PageState;
use Phem\State\StateJson;
use Phem\State\UrlQuery;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Loads a page with the HTTP kernel, and sends its events to the URL of the page, as the
 * `phem` Stimulus controller does. The access rules of the page, the signature of the state,
 * and the session (its cookie) apply as in a browser.
 *
 * @internal
 */
final class PageDriver implements Driver
{
    private string $html = '';
    private string $page = '';
    private string $state = '{}';
    private string $signature = '';
    /** The size under which an event sends the state, as the `phem` controller does (see StateCache::inlineSize()). */
    private int|float $inline = \INF;
    private int $status = 0;
    private ?string $redirect = null;
    private string $url;

    /**
     * @param array<string, string> $server  more server values and headers (`HTTP_ACCEPT_LANGUAGE`) for each request
     * @param array<string, string> $cookies the cookies of the browser
     */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $uri,
        private readonly array $server = [],
        private array $cookies = [],
        private readonly KeptRuns $runs = new KeptRuns(),
    ) {
        $this->url = $this->uri;

        [$request, , $content] = $this->send(Request::create($this->uri, 'GET', cookies: $this->cookies, server: $this->server));
        $this->html = self::finishStream($content);
        $this->page = (string) $request->attributes->get('_phem_page', '');

        if (1 === preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"(?: data-phem-query-value="([^"]*)")?(?: data-phem-state-inline-value="(\d+)")?/', $this->html, $m)) {
            $this->state = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5);
            $this->signature = html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5);

            if (isset($m[3]) && '' !== $m[3]) {
                $this->query(json_decode(html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5), true, 512, \JSON_THROW_ON_ERROR));
            }

            if (isset($m[4])) {
                $this->inline = (int) $m[4];
            }
        }
    }

    public function html(): string
    {
        return $this->html;
    }

    public function isDocument(): bool
    {
        return true;
    }

    public function dispatch(array $handlers, array $event, array $bound = [], array $stale = []): EventResult
    {
        if ('' === $this->signature) {
            throw new \LogicException(\sprintf('The response of "%s" is not a page with a state (status %d): it has no events.', $this->uri, $this->status));
        }

        $this->redirect = null;
        $files = $event['files'] ?? [];
        unset($event['files']);

        // As the browser: only the signature of a large state, and the state again when the server
        // does not have it. A small state and an event with files always go with the event.
        $response = $this->post($handlers, $event, $bound, $files, [] !== $files || \strlen($this->state) < $this->inline, $stale);
        if ([] === $files && 409 === $response->getStatusCode() && '{"state":"required"}' === $response->getContent()) {
            $response = $this->post($handlers, $event, $bound, $files, true, $stale);
        }

        if (409 === $response->getStatusCode()) {
            return EventResult::reload();
        }

        if (200 !== $response->getStatusCode()) {
            return EventResult::error($response->getStatusCode());
        }

        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('An event answered with no JSON object.');
        }

        if (\is_string($data['redirect'] ?? null)) {
            return EventResult::redirect($this->redirect = $data['redirect']);
        }

        // As the browser: the runs of kept fibers are in the shape before the event, and the
        // response has only the entries of the state that changed.
        $updates = $this->updates((string) $data['streams']);
        $this->state = StateJson::apply($this->state, \is_array($data['changes'] ?? null) ? $data['changes'] : []);
        $this->signature = (string) $data['signature'];
        $this->query($data['query'] ?? null);

        return EventResult::updates($updates, self::events($data['events'] ?? []), \is_string($data['sender'] ?? null) ? $data['sender'] : null);
    }

    /**
     * Posts an event to the URL that the browser shows, as the controller sends it. With files,
     * the JSON is the `_phem` part of a multipart body.
     *
     * @param list<string>                      $handlers
     * @param array<string, mixed>              $event
     * @param array<string, mixed>              $bound
     * @param list<array{0: string, 1: string}> $files    [field name, path] pairs
     * @param list<string>                      $stale
     */
    private function post(array $handlers, array $event, array $bound, array $files, bool $withState, array $stale = []): Response
    {
        $state = $withState ? ['state' => $this->state] : [];
        // As the browser: one id, null for no handler, or a list for a load of many boundaries.
        $handler = match (\count($handlers)) {
            0 => null,
            1 => $handlers[0],
            default => $handlers,
        };
        $body = json_encode($state + ['signature' => $this->signature, 'handler' => $handler, 'event' => $event, 'bound' => (object) $bound] + ([] !== $stale ? ['stale' => $stale] : []), \JSON_THROW_ON_ERROR);

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PHEM_EVENT' => '1',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ] + $this->server;

        $request = [] === $files
            ? Request::create($this->url, 'POST', cookies: $this->cookies, server: ['CONTENT_TYPE' => 'application/json'] + $server, content: $body)
            : Request::create($this->url, 'POST', [EventBody::PART => $body], $this->cookies, TestFiles::of($files), ['CONTENT_TYPE' => 'multipart/form-data; boundary=phem'] + $server);

        return $this->send($request)[1];
    }

    public function state(?string $fiber = null): array
    {
        $data = json_decode($this->state, true, 512, \JSON_THROW_ON_ERROR);
        $fibers = \is_array($data) ? PageState::fromArray($data)->fibers : [];

        return $fibers[$fiber ?? Fiber::idOf('/'.$this->page.'#0')] ?? [];
    }

    /** @return array<string, string> the cookies of the browser, with the cookies of the responses */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function redirect(): ?string
    {
        return $this->redirect;
    }

    /**
     * Handles a request as a server does, and keeps the cookies of the response. A server error
     * throws its exception, so the test shows it with its trace.
     *
     * @return array{0: Request, 1: Response, 2: string} the request, the response and its content (the chunks of a streamed response)
     */
    private function send(Request $request): array
    {
        $error = null;
        $listener = static function (ExceptionEvent $event) use (&$error): void {
            $error ??= $event->getThrowable();
        };
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $listener, 2048);

        try {
            $response = $this->kernel->handle($request);

            $content = StreamedContent::of($response);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }
        } finally {
            $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $listener);
        }

        if ($response->getStatusCode() >= 500 && $error instanceof \Throwable) {
            throw $error;
        }

        $this->status = $response->getStatusCode();
        $this->redirect = $response->isRedirect() ? $response->headers->get('Location') : null;

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } elseif (null !== $cookie->getValue()) {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return [$request, $response, $content];
    }

    /**
     * The page as the browser has it at the end of a stream (see Phem\Http\PageRenderer): each
     * `<Suspense>` boundary of the stream in place of its fallback, and the state on the root
     * element.
     */
    private static function finishStream(string $html): string
    {
        if (!str_contains($html, 'data-phem-streaming')) {
            return $html;
        }

        $document = HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8');
        $root = $document->querySelector('[data-phem-streaming]');
        if (null === $root) {
            return $html;
        }

        foreach ($document->querySelectorAll('template[data-phem-for]') as $template) {
            $target = $root->querySelector('[data-phem-c="'.$template->getAttribute('data-phem-for').'"]');
            if (null !== $target) {
                $target->outerHTML = $template->innerHTML;
            }

            if ('script' === $template->nextElementSibling?->localName) {
                $template->nextElementSibling->remove();
            }

            $template->remove();
        }

        $state = $document->querySelector('template[data-phem-root-state]');
        if (null === $state || $state->hasAttribute('data-phem-stream-error')) {
            throw new \LogicException('The stream of the page failed after the page: the page has no state (see the log).');
        }

        $root->removeAttribute('data-phem-streaming');
        foreach ($state->attributes as $attribute) {
            if ('data-phem-root-state' !== $attribute->name) {
                $root->setAttribute($attribute->name, $attribute->value);
            }
        }

        $state->remove();

        return $document->saveHtml();
    }

    /** Writes the query of the #[Url] properties into the URL, as the browser does. */
    private function query(mixed $query): void
    {
        /** @var array<string, string|list<string>|null> $values */
        $values = \is_array($query) && \is_array($query['values'] ?? null) ? $query['values'] : [];
        $this->url = UrlQuery::merge($this->url, $values);
    }

    /** @return list<array{name: string, detail: mixed}> */
    private static function events(mixed $events): array
    {
        if (!\is_array($events)) {
            throw new \UnexpectedValueException('The browser events of an event answer are not a list.');
        }

        $list = [];
        foreach ($events as $event) {
            if (!\is_array($event) || !\is_string($event['name'] ?? null)) {
                throw new \UnexpectedValueException('A browser event of an event answer has no name.');
            }

            $list[] = ['name' => $event['name'], 'detail' => $event['detail'] ?? null];
        }

        return $list;
    }

    /**
     * The fibers in the Turbo Streams of an event, with a placeholder for each kept fiber of a run
     * (see KeptRuns), in the shape of the state before the event.
     *
     * @return array<string, string> fiber id => the new HTML of its element
     */
    private function updates(string $streams): array
    {
        $before = PageState::fromArray((array) json_decode($this->state, true))->shape;

        $updates = [];
        foreach (self::streams($streams) as $id => $html) {
            $updates[$id] = $this->runs->expand($html, $before) ?? throw new \UnexpectedValueException(\sprintf('The event keeps a run of components in "%s" that the shape of the page does not have.', $id));
        }

        return $updates;
    }

    /**
     * The fibers in the Turbo Streams of an event (see TurboStreams). A string parser, not an
     * HTML parser: an HTML parser drops the table rows of a `<template>` in some contexts.
     *
     * @return array<string, string> fiber id => the new HTML of its element
     */
    private static function streams(string $streams): array
    {
        $open = '#<turbo-stream action="replace" method="morph" targets="\[data-phem-c=([^"\]]*)\]"><template>#';
        $close = '</template></turbo-stream>';
        $parts = preg_split($open, $streams, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $updates = [];
        for ($i = 1, $n = \count($parts); $i + 1 < $n; $i += 2) {
            $updates[$parts[$i]] = str_ends_with($parts[$i + 1], $close) ? substr($parts[$i + 1], 0, -\strlen($close)) : $parts[$i + 1];
        }

        return $updates;
    }
}
