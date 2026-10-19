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

use Phem\Rendering\Fiber;
use Phem\State\PageState;
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
    ) {
        $this->url = $this->uri;
        [$request, $response] = $this->send(Request::create($this->uri, 'GET', cookies: $this->cookies, server: $this->server));
        $this->html = (string) $response->getContent();
        $this->page = (string) $request->attributes->get('_phem_page', '');
        if (1 === preg_match('/data-phem-state-value="([^"]*)" data-phem-signature-value="([^"]*)"(?: data-phem-query-value="([^"]*)")?/', $this->html, $m)) {
            $this->state = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5);
            $this->signature = html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5);
            if (isset($m[3])) {
                $this->query(json_decode(html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5), true, 512, \JSON_THROW_ON_ERROR));
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

    public function dispatch(?string $handler, array $event, array $bound = []): EventResult
    {
        if ('' === $this->signature) {
            throw new \LogicException(\sprintf('The response of "%s" is not a page with a state (status %d): it has no events.', $this->uri, $this->status));
        }
        $this->redirect = null;
        $body = json_encode(['state' => $this->state, 'signature' => $this->signature, 'handler' => $handler, 'event' => $event, 'bound' => (object) $bound], \JSON_THROW_ON_ERROR);
        // To the URL that the browser shows, as the controller sends it.
        [, $response] = $this->send(Request::create($this->url, 'POST', cookies: $this->cookies, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PHEM_EVENT' => '1',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ] + $this->server, content: $body));

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
        $this->state = (string) $data['state'];
        $this->signature = (string) $data['signature'];
        $this->query($data['query'] ?? null);

        return EventResult::updates(self::streams((string) $data['streams']), self::events($data['events'] ?? []));
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
     * @return array{0: Request, 1: Response}
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

        return [$request, $response];
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
     * The fibers in the Turbo Streams of an event (see TurboStreams). A string parser, not an
     * HTML parser: an HTML parser drops the table rows of a `<template>` in some contexts.
     *
     * @return array<string, string> fiber id => inner HTML
     */
    private static function streams(string $streams): array
    {
        $open = '#<turbo-stream action="update" method="morph" target="([^"]*)"><template>#';
        $close = '</template></turbo-stream>';
        $parts = preg_split($open, $streams, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $updates = [];
        for ($i = 1, $n = \count($parts); $i + 1 < $n; $i += 2) {
            $updates[$parts[$i]] = str_ends_with($parts[$i + 1], $close) ? substr($parts[$i + 1], 0, -\strlen($close)) : $parts[$i + 1];
        }

        return $updates;
    }
}
