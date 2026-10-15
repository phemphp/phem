<?php

declare(strict_types=1);

namespace Rephp\Tests\Server;

use PHPUnit\Framework\TestCase;

/**
 * Starts the real server in a child process and talks to it over TCP.
 */
final class HttpServerTest extends TestCase
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $logFile;

    private int $port;

    protected function setUp(): void
    {
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    public function testTwoRequestsOnOneKeepAliveConnection(): void
    {
        $socket = $this->connect();

        fwrite($socket, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        $first = $this->readResponse($socket);

        fwrite($socket, "GET /echo?name=Ada HTTP/1.1\r\nHost: test\r\n\r\n");
        $second = $this->readResponse($socket);

        self::assertSame(200, $first['status']);
        self::assertSame('<h1>Hello</h1>', $first['body']);
        self::assertSame('keep-alive', $first['headers']['connection']);
        self::assertSame('rephp', $first['headers']['server']);
        self::assertArrayHasKey('date', $first['headers']);
        self::assertSame(200, $second['status']);
        self::assertSame(['name' => 'Ada'], $this->json($second)['query']);
    }

    public function testPostEcho(): void
    {
        $socket = $this->connect();
        $body = 'title=Hello+world';

        fwrite($socket, "POST /echo HTTP/1.1\r\nHost: test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".\strlen($body)."\r\n\r\n".$body);
        $response = $this->readResponse($socket);

        self::assertSame(200, $response['status']);
        self::assertSame([
            'method' => 'POST',
            'query' => [],
            'type' => 'application/x-www-form-urlencoded',
            'body' => $body,
        ], $this->json($response));
    }

    public function testChunkedPostAndExpectContinue(): void
    {
        $socket = $this->connect();

        fwrite($socket, "POST /echo HTTP/1.1\r\nHost: test\r\nTransfer-Encoding: chunked\r\nExpect: 100-continue\r\n\r\n");
        self::assertSame("HTTP/1.1 100 Continue\r\n", fgets($socket));
        self::assertSame("\r\n", fgets($socket));

        fwrite($socket, "3\r\nabc\r\n2\r\nde\r\n0\r\n\r\n");
        $response = $this->readResponse($socket);

        self::assertSame('abcde', $this->json($response)['body']);
    }

    public function testPipelinedRequestsAreAnsweredInOrder(): void
    {
        $socket = $this->connect();

        fwrite($socket, "GET /echo?n=1 HTTP/1.1\r\n\r\nGET /echo?n=2 HTTP/1.1\r\n\r\nGET /echo?n=3 HTTP/1.1\r\nConnection: close\r\n\r\n");

        self::assertSame(['n' => '1'], $this->json($this->readResponse($socket))['query']);
        self::assertSame(['n' => '2'], $this->json($this->readResponse($socket))['query']);
        $last = $this->readResponse($socket);
        self::assertSame(['n' => '3'], $this->json($last)['query']);
        self::assertSame('close', $last['headers']['connection']);
        self::assertSame('', stream_get_contents($socket), 'The server closes the connection.');
    }

    public function testLargeResponseWithPartialWritesDoesNotBlockOtherClients(): void
    {
        $slow = $this->connect();
        fwrite($slow, "GET /big HTTP/1.1\r\nHost: test\r\n\r\n");
        usleep(100_000); // The slow client does not read yet: the server must buffer and wait for Event::Write.

        self::assertSame(200, $this->request("GET /hello HTTP/1.1\r\nHost: test\r\n\r\n")['status']);

        $response = $this->readResponse($slow);
        self::assertSame(4_194_304, \strlen($response['body']));
        self::assertSame(str_repeat('0123456789abcdef', 262_144), $response['body']);
    }

    public function testNotFound(): void
    {
        $response = $this->request("GET /missing HTTP/1.1\r\nHost: test\r\n\r\n");

        self::assertSame(404, $response['status']);
        self::assertSame("Not Found\n", $response['body']);
    }

    public function testStaticFileAndNotModified(): void
    {
        $response = $this->request("GET /style.css HTTP/1.1\r\nHost: test\r\n\r\n");

        self::assertSame(200, $response['status']);
        self::assertSame("body { color: red; }\n", $response['body']);
        self::assertSame('text/css; charset=UTF-8', $response['headers']['content-type']);
        self::assertSame('no-cache', $response['headers']['cache-control']);

        $etag = $response['headers']['etag'];
        $cached = $this->request("GET /style.css HTTP/1.1\r\nHost: test\r\nIf-None-Match: {$etag}\r\n\r\n");

        self::assertSame(304, $cached['status']);
        self::assertSame('', $cached['body']);

        $index = $this->request("GET / HTTP/1.1\r\nHost: test\r\n\r\n");
        self::assertSame("<h1>Static index</h1>\n", $index['body']);
    }

    public function testHeadHasHeadersButNoBody(): void
    {
        $socket = $this->connect();

        fwrite($socket, "HEAD /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        $head = $this->readResponse($socket, head: true);

        // The next response on the same connection proves that no body bytes came after the HEAD response.
        fwrite($socket, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        $get = $this->readResponse($socket);

        self::assertSame(200, $head['status']);
        self::assertSame('14', $head['headers']['content-length']);
        self::assertSame('', $head['body']);
        self::assertSame('<h1>Hello</h1>', $get['body']);
    }

    public function testHandlerExceptionGives500AndIsLogged(): void
    {
        $response = $this->request("GET /boom HTTP/1.1\r\nHost: test\r\n\r\n");

        self::assertSame(500, $response['status']);
        self::assertSame("Internal Server Error\n", $response['body']);

        $log = $this->waitForLog('GET /boom 500');
        self::assertStringContainsString('RuntimeException: Kaboom', $log);
        self::assertStringContainsString('#0 ', $log, 'The log has the stack trace.');
    }

    public function testMalformedRequestGives400AndCloses(): void
    {
        $socket = $this->connect();

        fwrite($socket, "NOT HTTP\r\n\r\n");
        $response = $this->readResponse($socket);

        self::assertSame(400, $response['status']);
        self::assertSame('close', $response['headers']['connection']);
        self::assertSame('', stream_get_contents($socket));
    }

    public function testBodyTooLargeGives413(): void
    {
        $response = $this->request("POST /echo HTTP/1.1\r\nHost: test\r\nContent-Length: 5000\r\n\r\n");

        self::assertSame(413, $response['status']);
    }

    public function testHttp10ClosesAfterResponse(): void
    {
        $socket = $this->connect();

        fwrite($socket, "GET /hello HTTP/1.0\r\n\r\n");
        $response = $this->readResponse($socket);

        self::assertSame('HTTP/1.1 200 OK', $response['statusLine']);
        self::assertSame('close', $response['headers']['connection']);
        self::assertSame('', stream_get_contents($socket));
    }

    public function testAccessLogLine(): void
    {
        $this->request("GET /hello?x=1 HTTP/1.1\r\nHost: test\r\n\r\n");

        $log = $this->waitForLog('GET /hello');

        self::assertMatchesRegularExpression('#^\[[^\]]+\] GET /hello 200 14 \d+\.\dms$#m', $log);
    }

    public function testIdleConnectionIsClosed(): void
    {
        $this->stopServer();
        $this->startServer(idleTimeout: 0.3);

        $socket = $this->connect();
        fwrite($socket, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        self::assertSame(200, $this->readResponse($socket)['status']);

        $start = microtime(true);
        self::assertSame('', stream_get_contents($socket), 'The server closes the idle connection.');
        self::assertLessThan(3.0, microtime(true) - $start);
    }

    public function testStopFromHandlerEndsRun(): void
    {
        $response = $this->request("GET /stop HTTP/1.1\r\nHost: test\r\n\r\n");

        self::assertSame("bye\n", $response['body']);
        self::assertSame('close', $response['headers']['connection']);
        self::assertSame("stopped\n", stream_get_contents($this->pipes[1]));

        $deadline = microtime(true) + 3;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFalse(proc_get_status($this->process)['running']);
    }

    private function startServer(float $idleTimeout = 15.0): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'rephp-log-');
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/Fixtures/server.php', (string) $idleTimeout],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->logFile, 'w']],
            $this->pipes,
        );

        if ($process === false) {
            self::fail('Cannot start the server process.');
        }

        $this->process = $process;
        $read = [$this->pipes[1]];
        $write = $except = null;

        if (stream_select($read, $write, $except, 10) !== 1) {
            self::fail('The server did not print its port. Log: '.file_get_contents($this->logFile));
        }

        $port = trim((string) fgets($this->pipes[1]));

        if (!ctype_digit($port)) {
            self::fail('Unexpected server output: '.$port.' Log: '.file_get_contents($this->logFile));
        }

        $this->port = (int) $port;
    }

    private function stopServer(): void
    {
        if ($this->process === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }

        proc_terminate($this->process, 9);
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        @unlink($this->logFile);
    }

    /**
     * @return resource
     */
    private function connect(): mixed
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $error, 5);

        if ($socket === false) {
            self::fail("Cannot connect: {$error}");
        }

        stream_set_timeout($socket, 5);

        return $socket;
    }

    /**
     * @return array{statusLine: string, status: int, headers: array<string, string>, body: string}
     */
    private function request(string $raw): array
    {
        $socket = $this->connect();
        fwrite($socket, $raw);

        return $this->readResponse($socket);
    }

    /**
     * @param resource $socket
     *
     * @return array{statusLine: string, status: int, headers: array<string, string>, body: string}
     */
    private function readResponse(mixed $socket, bool $head = false): array
    {
        $statusLine = rtrim((string) fgets($socket), "\r\n");

        if (preg_match('#^HTTP/1\.1 (\d{3}) #', $statusLine, $m) !== 1) {
            self::fail('Invalid status line: '.var_export($statusLine, true));
        }

        $headers = [];

        while (($line = fgets($socket)) !== false && $line !== "\r\n") {
            [$name, $value] = explode(':', rtrim($line, "\r\n"), 2);
            $headers[strtolower($name)] = trim($value);
        }

        $status = (int) $m[1];
        $length = $head || $status === 304 ? 0 : (int) ($headers['content-length'] ?? 0);
        $body = '';

        while (\strlen($body) < $length && !feof($socket)) {
            $body .= (string) fread($socket, $length - \strlen($body));
        }

        return ['statusLine' => $statusLine, 'status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param array{body: string} $response
     *
     * @return array<string, mixed>
     */
    private function json(array $response): array
    {
        return json_decode($response['body'], true, flags: \JSON_THROW_ON_ERROR);
    }

    private function waitForLog(string $needle): string
    {
        $deadline = microtime(true) + 3;

        do {
            $log = (string) file_get_contents($this->logFile);

            if (str_contains($log, $needle)) {
                return $log;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail("The log does not contain \"{$needle}\": {$log}");
    }
}
