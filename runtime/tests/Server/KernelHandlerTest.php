<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Rephp\Server\KernelHandler;
use Rephp\Server\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class KernelHandlerTest extends TestCase
{
    public function testConvertsTheRequest(): void
    {
        $request = (new KernelHandler($this->kernel(), 'example.test', 8080))->toSymfony(new Request(
            'POST',
            '/form',
            ['page' => '2'],
            ['content-type' => 'application/x-www-form-urlencoded', 'host' => 'example.test:8080', 'cookie' => 'a=1; b=x%20y', 'x-custom' => 'yes'],
            'name=Ada&profession=mathematician',
            '203.0.113.7:51234',
        ));

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/form', $request->getPathInfo());
        self::assertSame('2', $request->query->get('page'));
        self::assertSame('Ada', $request->request->get('name'));
        self::assertSame(['a' => '1', 'b' => 'x y'], $request->cookies->all());
        self::assertSame('yes', $request->headers->get('X-Custom'));
        self::assertSame('example.test', $request->getHost());
        self::assertSame('203.0.113.7', $request->getClientIp(), 'the real peer, never 127.0.0.1');
        self::assertSame('51234', $request->server->get('REMOTE_PORT'));
        self::assertSame('name=Ada&profession=mathematician', $request->getContent());
    }

    public function testIpv6PeerAddress(): void
    {
        $request = (new KernelHandler($this->kernel()))->toSymfony(new Request('GET', '/', remoteAddress: '[2001:db8::1]:443'));

        self::assertSame('2001:db8::1', $request->getClientIp());
    }

    public function testConvertsTheResponse(): void
    {
        $sent = new SymfonyResponse('{"ok":true}', 201, ['Content-Type' => 'application/json']);
        $sent->headers->setCookie(Cookie::create('a', '1'));
        $sent->headers->setCookie(Cookie::create('b', '2'));

        $response = (new KernelHandler($this->kernel($sent)))->handle(new Request('GET', '/'));

        self::assertSame(201, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame(['application/json'], $response->headers['Content-Type']);
        self::assertCount(2, $response->headers['Set-Cookie']);
    }

    public function testHeadKeepsTheBodyForContentLengthAndStreamsAreCaptured(): void
    {
        $head = (new KernelHandler($this->kernel(new SymfonyResponse('<h1>Hi</h1>'))))->handle(new Request('HEAD', '/'));
        self::assertSame('<h1>Hi</h1>', $head->body, 'the server sends only the headers, with this length');

        $streamed = new StreamedResponse(static function (): void {
            echo 'chunk 1, ';
            echo 'chunk 2';
        });
        self::assertSame('chunk 1, chunk 2', (new KernelHandler($this->kernel($streamed)))->handle(new Request('GET', '/'))->body);
    }

    private function kernel(?SymfonyResponse $response = null): HttpKernelInterface
    {
        return new class($response ?? new SymfonyResponse()) implements HttpKernelInterface {
            public function __construct(private readonly SymfonyResponse $response)
            {
            }

            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return $this->response;
            }
        };
    }
}
