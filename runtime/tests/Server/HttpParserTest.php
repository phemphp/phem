<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephp\Server\HttpException;
use Rephp\Server\HttpParser;

final class HttpParserTest extends TestCase
{
    public function testSimpleGet(): void
    {
        $parser = new HttpParser();
        $parser->feed("GET /hello/world?name=Ada&tags[]=a&tags[]=b HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $parsed = $parser->next();

        self::assertNotNull($parsed);
        self::assertSame('GET', $parsed->request->method);
        self::assertSame('/hello/world', $parsed->request->path);
        self::assertSame(['name' => 'Ada', 'tags' => ['a', 'b']], $parsed->request->query);
        self::assertSame('', $parsed->request->body);
        self::assertSame('HTTP/1.1', $parsed->protocol);
        self::assertTrue($parsed->keepAlive);
        self::assertNull($parser->next());
        self::assertFalse($parser->hasBufferedData());
    }

    public function testHeadersAreCaseInsensitiveAndRepeatedHeadersAreJoined(): void
    {
        $parser = new HttpParser();
        $parser->feed("GET / HTTP/1.1\r\nHOST: example.com\r\nX-Custom:   padded value  \r\nAccept: text/html\r\naccept: application/json\r\n\r\n");

        $request = $parser->next()?->request;

        self::assertNotNull($request);
        self::assertSame('example.com', $request->header('Host'));
        self::assertSame('padded value', $request->header('x-custom'));
        self::assertSame('text/html, application/json', $request->headers['accept']);
    }

    public function testRequestArrivingInPieces(): void
    {
        $parser = new HttpParser();
        $parser->feed("POST /form HTTP/1.1\r\nContent-Le");
        self::assertNull($parser->next());

        $parser->feed("ngth: 11\r\n\r\nhello");
        self::assertNull($parser->next());

        $parser->feed(' world');
        self::assertSame('hello world', $parser->next()?->request->body);
    }

    public function testContentLengthBody(): void
    {
        $parser = new HttpParser();
        $parser->feed("POST /echo HTTP/1.1\r\nContent-Length: 5\r\n\r\nabcde");

        $request = $parser->next()?->request;

        self::assertSame('POST', $request?->method);
        self::assertSame('abcde', $request?->body);
    }

    public function testChunkedBody(): void
    {
        $parser = new HttpParser();
        $parser->feed("POST /upload HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=1\r\nhello\r\n");
        self::assertNull($parser->next());

        $parser->feed("6\r\n world\r\n0\r\nX-Trailer: yes\r\n\r\nGET /next HTTP/1.1\r\n\r\n");

        self::assertSame('hello world', $parser->next()?->request->body);
        self::assertSame('/next', $parser->next()?->request->path);
    }

    public function testPipelinedRequestsComeOutInOrder(): void
    {
        $parser = new HttpParser();
        $parser->feed(
            "GET /first HTTP/1.1\r\n\r\n"
            ."POST /second HTTP/1.1\r\nContent-Length: 3\r\n\r\nabc"
            ."GET /third HTTP/1.1\r\nConnection: close\r\n\r\n",
        );

        $first = $parser->next();
        $second = $parser->next();
        $third = $parser->next();

        self::assertSame('/first', $first?->request->path);
        self::assertSame('abc', $second?->request->body);
        self::assertSame('/third', $third?->request->path);
        self::assertFalse($third->keepAlive);
        self::assertNull($parser->next());
    }

    public function testKeepAliveRules(): void
    {
        self::assertTrue($this->parse("GET / HTTP/1.1\r\n\r\n")->keepAlive);
        self::assertFalse($this->parse("GET / HTTP/1.1\r\nConnection: Close\r\n\r\n")->keepAlive);
        self::assertFalse($this->parse("GET / HTTP/1.0\r\n\r\n")->keepAlive);
        self::assertTrue($this->parse("GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n")->keepAlive);
    }

    public function testPathIsDecodedAndNormalized(): void
    {
        self::assertSame('/a b/c', $this->parse("GET /a%20b/./c HTTP/1.1\r\n\r\n")->request->path);
        self::assertSame('/etc/passwd', $this->parse("GET /../../etc/passwd HTTP/1.1\r\n\r\n")->request->path);
        self::assertSame('/secret', $this->parse("GET /x/%2e%2e/%2E%2E/secret HTTP/1.1\r\n\r\n")->request->path);
        self::assertSame('/docs/', $this->parse("GET //docs// HTTP/1.1\r\n\r\n")->request->path);
        self::assertSame('/page', $this->parse("GET http://example.com/page?x=1 HTTP/1.1\r\n\r\n")->request->path);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function malformedRequests(): iterable
    {
        yield 'garbage request line' => ["HELLO\r\n\r\n", 400];
        yield 'missing protocol' => ["GET /\r\n\r\n", 400];
        yield 'bad header line' => ["GET / HTTP/1.1\r\nNoColonHere\r\n\r\n", 400];
        yield 'folded header' => ["GET / HTTP/1.1\r\nX-A: 1\r\n continued\r\n\r\n", 400];
        yield 'relative target' => ["GET index.html HTTP/1.1\r\n\r\n", 400];
        yield 'bad content length' => ["POST / HTTP/1.1\r\nContent-Length: -1\r\n\r\n", 400];
        yield 'both length and chunked' => ["POST / HTTP/1.1\r\nContent-Length: 1\r\nTransfer-Encoding: chunked\r\n\r\n", 400];
        yield 'bad chunk size' => ["POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\nzz\r\n", 400];
        yield 'null byte in path' => ["GET /a%00b HTTP/1.1\r\n\r\n", 400];
        yield 'unknown transfer encoding' => ["POST / HTTP/1.1\r\nTransfer-Encoding: gzip\r\n\r\n", 501];
        yield 'HTTP/2 request line' => ["GET / HTTP/2.0\r\n\r\n", 505];
    }

    #[DataProvider('malformedRequests')]
    public function testMalformedRequestIsRejected(string $raw, int $status): void
    {
        $parser = new HttpParser();
        $parser->feed($raw);

        try {
            $parser->next();
            self::fail('The parser accepted a malformed request.');
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);
        }
    }

    public function testContentLengthBodyTooLarge(): void
    {
        $parser = new HttpParser(maxBodyBytes: 10);
        // The parser rejects it from the headers, before the body arrives.
        $parser->feed("POST / HTTP/1.1\r\nContent-Length: 11\r\n\r\n");

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);
        $parser->next();
    }

    public function testChunkedBodyTooLarge(): void
    {
        $parser = new HttpParser(maxBodyBytes: 10);
        $parser->feed("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n6\r\nabcdef\r\n6\r\n");

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);
        $parser->next();
    }

    public function testHeadersTooLarge(): void
    {
        $parser = new HttpParser(maxHeaderBytes: 64);
        $parser->feed("GET / HTTP/1.1\r\nX-Big: ".str_repeat('a', 100));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(431);
        $parser->next();
    }

    public function testExpectContinueIsSignaledOnce(): void
    {
        $parser = new HttpParser();
        $parser->feed("POST / HTTP/1.1\r\nExpect: 100-continue\r\nContent-Length: 3\r\n\r\n");

        self::assertNull($parser->next());
        self::assertTrue($parser->takeContinue());
        self::assertNull($parser->next());
        self::assertFalse($parser->takeContinue());

        $parser->feed('abc');
        self::assertSame('abc', $parser->next()?->request->body);
    }

    private function parse(string $raw): \Rephp\Server\ParsedRequest
    {
        $parser = new HttpParser();
        $parser->feed($raw);

        return $parser->next() ?? self::fail('Incomplete request.');
    }
}
