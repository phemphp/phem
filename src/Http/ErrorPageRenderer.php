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

use Phem\Controller\EventController;
use Phem\Metadata\ComponentMetadata;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The HTML of an HTTP error: the error page of the application (see ErrorPages). It decorates
 * the HTML error renderer of Symfony, outside the Twig renderer, so the order is: the Phem error
 * page, the Twig template (`@Twig/Exception/error404.html.twig`), the page of Symfony. The
 * status and the headers of the error stay (a `Retry-After`, an `Allow`).
 *
 * The inner renderer renders the error:
 * - when `phem.show_exceptions` is true (`%kernel.debug%` by default): the page of the
 *   exception. The `showException` attribute of the request wins, so the preview at
 *   `/_error/{code}` shows the error page;
 * - for a Phem event: the browser does not show the body of a failed event (`phem:error`);
 * - when no error page matches the status and the path;
 * - in production, when the error page throws: an error page must not fail. In debug, the
 *   exception goes on, so the bug of the page shows.
 *
 * The path of the request chooses the page. The preview has the path of its own route, so its
 * `path` query parameter gives the section: `/_error/404?path=/admin`. With no request
 * (`error:dump`), the page renders for a GET of `/` at the host of the router.
 */
final class ErrorPageRenderer implements ErrorRendererInterface
{
    /** The route of the preview of Symfony (`/_error/{code}`). */
    private const string PREVIEW_ROUTE = '_preview_error';

    public function __construct(
        private readonly ErrorRendererInterface $inner,
        private readonly ErrorPages $pages,
        private readonly StatelessPageRenderer $renderer,
        private readonly RequestStack $requests,
        private readonly bool $showExceptions = false,
        private readonly bool $debug = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $charset = 'UTF-8',
        private readonly ?RequestContext $context = null,
        private readonly ComponentMetadata $metadata = new ComponentMetadata(),
    ) {
    }

    public function render(\Throwable $exception): FlattenException
    {
        $request = $this->requests->getCurrentRequest();
        if (true === $request?->headers->has(EventController::HEADER) || HtmlErrorRenderer::isDebug($this->requests, $this->showExceptions)()) {
            return $this->inner->render($exception);
        }

        $page = $this->pages->find(self::status($exception), $this->path($request));
        if (null === $page) {
            return $this->inner->render($exception);
        }

        try {
            return $this->page($page, $exception, $request);
        } catch (\Throwable $e) {
            if ($this->debug) {
                throw $e;
            }

            $this->logger->error('The error page "{page}" failed: {message}', ['page' => $page, 'message' => $e->getMessage(), 'exception' => $e]);
        }

        return $this->inner->render($exception);
    }

    private function page(string $page, \Throwable $exception, ?Request $request): FlattenException
    {
        // The headers of the exception win, as in HtmlErrorRenderer.
        $error = FlattenException::createFromThrowable($exception, null, ['Content-Type' => 'text/html; charset='.$this->charset]);
        $values = ['status' => $error->getStatusCode(), 'exception' => $this->exception($page, $exception, $error)];
        if (null !== $request) {
            return $error->setAsString($this->renderer->html($page, $values));
        }

        // `error:dump` renders the pages with no request.
        $this->requests->push($this->rootRequest());

        try {
            return $error->setAsString($this->renderer->html($page, $values));
        } finally {
            $this->requests->pop();
        }
    }

    /**
     * The status of the error, as FlattenException::createFromThrowable() gives it. The page is
     * known before the FlattenException is made: with no page, only the inner renderer makes one.
     */
    private static function status(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof RequestExceptionInterface => 400,
            default => 500,
        };
    }

    /** The path that chooses the page: the path of the request, or the `path` query parameter of the preview. */
    private function path(?Request $request): string
    {
        $main = $this->requests->getMainRequest();
        if (self::PREVIEW_ROUTE === $main?->attributes->get('_route')) {
            return '/'.ltrim($main->query->getString('path'), '/');
        }

        return $request?->getPathInfo() ?? '/';
    }

    /**
     * The `exception` value of the page: the FlattenException, or the exception itself when the
     * parameter has the type of an exception (`\Throwable $exception`).
     */
    private function exception(string $page, \Throwable $exception, FlattenException $error): \Throwable|FlattenException
    {
        $type = $this->metadata->pathProps($page)['exception'] ?? null;

        return $type instanceof \ReflectionNamedType && !$type->isBuiltin() && !is_a(FlattenException::class, $type->getName(), true) ? $exception : $error;
    }

    /** A GET of `/` at the scheme, the host and the port of the router (`framework.router.default_uri`). */
    private function rootRequest(): Request
    {
        if (null === $this->context) {
            return Request::create('/');
        }

        $https = 'https' === $this->context->getScheme();
        $port = $https ? $this->context->getHttpsPort() : $this->context->getHttpPort();
        $host = $this->context->getHost().(($https ? 443 : 80) === $port ? '' : ':'.$port);

        return Request::create($this->context->getScheme().'://'.$host.'/');
    }
}
