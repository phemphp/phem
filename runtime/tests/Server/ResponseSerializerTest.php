<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Rephp\Server\Response;
use Rephp\Server\ResponseSerializer;

final class ResponseSerializerTest extends TestCase
{
    public function testSerializesStatusLineHeadersAndBody(): void
    {
        $raw = ResponseSerializer::serialize(Response::html('<p>Hi</p>'), head: false, keepAlive: true);

        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);

        self::assertSame('HTTP/1.1 200 OK', $lines[0]);
        self::assertContains('Content-Length: 9', $lines);
        self::assertContains('Connection: keep-alive', $lines);
        self::assertContains('Content-Type: text/html; charset=UTF-8', $lines);
        self::assertContains('Server: rephp', $lines);
        self::assertMatchesRegularExpression('/\r\nDate: \w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT\r\n/', $raw);
        self::assertSame('<p>Hi</p>', $body);
    }

    public function testHeadKeepsContentLengthButDropsBody(): void
    {
        $raw = ResponseSerializer::serialize(Response::text('12345'), head: true, keepAlive: false);

        self::assertStringContainsString("Content-Length: 5\r\n", $raw);
        self::assertStringContainsString("Connection: close\r\n", $raw);
        self::assertStringEndsWith("\r\n\r\n", $raw);
    }

    public function testNotModifiedHasNoBodyAndNoLength(): void
    {
        $raw = ResponseSerializer::serialize(new Response(304, ['ETag' => '"x"'], 'ignored'), head: false, keepAlive: true);

        self::assertStringStartsWith("HTTP/1.1 304 Not Modified\r\n", $raw);
        self::assertStringNotContainsString('Content-Length', $raw);
        self::assertStringEndsWith("ETag: \"x\"\r\nServer: rephp\r\n\r\n", $raw);
    }

    public function testHandlerCannotOverrideFramingOrInjectHeaders(): void
    {
        $raw = ResponseSerializer::serialize(new Response(200, [
            'Content-Length' => '999',
            'X-Evil' => "a\r\nSet-Cookie: injected=1",
            'Set-Cookie' => ['a=1', 'b=2'],
        ], 'ok'), head: false, keepAlive: true);

        self::assertStringContainsString("Content-Length: 2\r\n", $raw);
        self::assertStringNotContainsString('999', $raw);
        self::assertStringContainsString("X-Evil: aSet-Cookie: injected=1\r\n", $raw);
        self::assertStringContainsString("Set-Cookie: a=1\r\nSet-Cookie: b=2\r\n", $raw);
    }

    public function testReasonPhrases(): void
    {
        self::assertSame('Not Found', ResponseSerializer::reason(404));
        self::assertSame('Content Too Large', ResponseSerializer::reason(413));
        self::assertSame('Client Error', ResponseSerializer::reason(499));
    }
}
