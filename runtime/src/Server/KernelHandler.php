<?php

declare(strict_types=1);

namespace Rephp\Server;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Connects the Io\Poll server to a Symfony kernel: it converts the request to HttpFoundation,
 * lets the kernel handle it, and converts the response back.
 *
 * Request bodies: JSON, text and `application/x-www-form-urlencoded` (not multipart).
 */
final class KernelHandler implements RequestHandler
{
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
    ) {
    }

    public function handle(Request $request): Response
    {
        $symfonyRequest = $this->toSymfony($request);
        $symfonyResponse = $this->kernel->handle($symfonyRequest);
        $response = $this->fromSymfony($symfonyRequest, $symfonyResponse);
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($symfonyRequest, $symfonyResponse);
        }

        return $response;
    }

    public function toSymfony(Request $request): SymfonyRequest
    {
        // The real client address: Symfony uses it for getClientIp(), trusted proxies and IP rules.
        $colon = strrpos($request->remoteAddress, ':');
        $server = [
            'SERVER_NAME' => $this->host,
            'SERVER_PORT' => $this->port,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => trim(false === $colon ? $request->remoteAddress : substr($request->remoteAddress, 0, $colon), '[]'),
            'REMOTE_PORT' => false === $colon ? '' : substr($request->remoteAddress, $colon + 1),
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => '',
        ];
        foreach ($request->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        $parameters = [];
        if ('POST' === $request->method && str_starts_with(strtolower($request->header('content-type') ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($request->body, $parameters);
        }

        $cookies = [];
        foreach (explode(';', $request->header('cookie') ?? '') as $pair) {
            [$name, $value] = explode('=', trim($pair), 2) + [1 => ''];
            if ('' !== $name) {
                $cookies[$name] = urldecode($value);
            }
        }

        $uri = $request->path.([] !== $request->query ? '?'.http_build_query($request->query) : '');

        return SymfonyRequest::create($uri, $request->method, $parameters, $cookies, [], $server, $request->body);
    }

    public function fromSymfony(SymfonyRequest $request, SymfonyResponse $response): Response
    {
        // Read the body before prepare(): it empties the content of a HEAD response, and the
        // server needs the length for Content-Length.
        $body = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse ? null : (string) $response->getContent();
        $response->prepare($request);
        if (null === $body) {
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
        }

        $headers = $response->headers->allPreserveCaseWithoutCookies();
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }

        return new Response($response->getStatusCode(), $headers, $body);
    }
}
