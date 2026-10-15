<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * Incremental HTTP/1.x request parser for one connection.
 *
 * Feed it the bytes as they arrive, then call next() until it returns null.
 * It throws HttpException for a request that it cannot accept.
 */
final class HttpParser
{
    private const string TOKEN = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";

    private string $buffer = '';

    private bool $continuePending = false;

    private bool $continueSent = false;

    public function __construct(
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly int $maxHeaderBytes = 16_384,
    ) {
    }

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function hasBufferedData(): bool
    {
        return $this->buffer !== '';
    }

    /**
     * True once for a request with "Expect: 100-continue" that waits for its body.
     */
    public function takeContinue(): bool
    {
        if (!$this->continuePending) {
            return false;
        }

        $this->continuePending = false;
        $this->continueSent = true;

        return true;
    }

    /**
     * @throws HttpException
     */
    public function next(): ?ParsedRequest
    {
        // RFC 9112 2.2: ignore empty lines before the request line.
        $this->buffer = ltrim($this->buffer, "\r\n");

        $headEnd = strpos($this->buffer, "\r\n\r\n");

        if ($headEnd === false) {
            if (\strlen($this->buffer) > $this->maxHeaderBytes) {
                throw new HttpException(431);
            }

            return null;
        }

        if ($headEnd > $this->maxHeaderBytes) {
            throw new HttpException(431);
        }

        $lines = explode("\r\n", substr($this->buffer, 0, $headEnd));
        [$method, $target, $protocol] = $this->parseRequestLine(array_shift($lines));
        $headers = $this->parseHeaders($lines);

        $body = $this->parseBody($headers, $headEnd + 4);

        if ($body === null) {
            $this->continuePending = !$this->continueSent
                && strtolower($headers['expect'] ?? '') === '100-continue';

            return null;
        }

        [$body, $consumed] = $body;
        $this->buffer = substr($this->buffer, $consumed);
        $this->continuePending = false;
        $this->continueSent = false;

        [$path, $query] = $this->parseTarget($target);

        return new ParsedRequest(
            new Request($method, $path, $query, $headers, $body),
            $protocol,
            $this->keepAlive($protocol, $headers['connection'] ?? ''),
        );
    }

    /**
     * Decodes and normalizes a URL path. Dot segments never go above the root.
     *
     * @throws HttpException
     */
    public static function normalizePath(string $rawPath): string
    {
        $decoded = rawurldecode($rawPath);

        if (str_contains($decoded, "\0")) {
            throw new HttpException(400, 'Invalid path');
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $decoded)) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        $path = '/'.implode('/', $segments);

        // Keep the trailing slash: it tells a directory from a file.
        if ($segments !== [] && preg_match('#/\.{0,2}$#', $decoded) === 1) {
            $path .= '/';
        }

        return $path;
    }

    /**
     * @return array{string, string, string}
     */
    private function parseRequestLine(string $line): array
    {
        if (preg_match('@^('.self::TOKEN.') (\S+) HTTP/(\d)\.(\d)$@', $line, $m) !== 1) {
            throw new HttpException(400, 'Malformed request line');
        }

        if ($m[3] !== '1') {
            throw new HttpException(505);
        }

        return [$m[1], $m[2], 'HTTP/1.'.$m[4]];
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            if (preg_match('@^('.self::TOKEN.'):[ \t]*(.*?)[ \t]*$@', $line, $m) !== 1) {
                throw new HttpException(400, 'Malformed header');
            }

            $name = strtolower($m[1]);
            $headers[$name] = isset($headers[$name]) ? $headers[$name].', '.$m[2] : $m[2];
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{string, int}|null the body and the total bytes of the request, or null when incomplete
     */
    private function parseBody(array $headers, int $offset): ?array
    {
        if (isset($headers['transfer-encoding'])) {
            if (isset($headers['content-length'])) {
                throw new HttpException(400, 'Both Transfer-Encoding and Content-Length');
            }

            if (strtolower($headers['transfer-encoding']) !== 'chunked') {
                throw new HttpException(501, 'Unsupported Transfer-Encoding');
            }

            return $this->parseChunked($offset);
        }

        $length = $headers['content-length'] ?? '0';

        if (preg_match('/^\d{1,18}$/', $length) !== 1) {
            throw new HttpException(400, 'Invalid Content-Length');
        }

        $length = (int) $length;

        if ($length > $this->maxBodyBytes) {
            throw new HttpException(413);
        }

        if (\strlen($this->buffer) - $offset < $length) {
            return null;
        }

        return [substr($this->buffer, $offset, $length), $offset + $length];
    }

    /**
     * @return array{string, int}|null
     */
    private function parseChunked(int $offset): ?array
    {
        $body = '';

        while (true) {
            $lineEnd = strpos($this->buffer, "\r\n", $offset);

            if ($lineEnd === false) {
                if (\strlen($this->buffer) - $offset > 1024) {
                    throw new HttpException(400, 'Chunk size line too long');
                }

                return null;
            }

            // Chunk extensions after ";" are allowed and ignored.
            $sizeLine = trim(explode(';', substr($this->buffer, $offset, $lineEnd - $offset), 2)[0]);

            if (preg_match('/^[0-9a-fA-F]{1,15}$/', $sizeLine) !== 1) {
                throw new HttpException(400, 'Invalid chunk size');
            }

            $size = (int) hexdec($sizeLine);
            $offset = $lineEnd + 2;

            if ($size === 0) {
                return $this->skipTrailers($body, $offset);
            }

            if (\strlen($body) + $size > $this->maxBodyBytes) {
                throw new HttpException(413);
            }

            if (\strlen($this->buffer) < $offset + $size + 2) {
                return null;
            }

            if (substr($this->buffer, $offset + $size, 2) !== "\r\n") {
                throw new HttpException(400, 'Invalid chunk');
            }

            $body .= substr($this->buffer, $offset, $size);
            $offset += $size + 2;
        }
    }

    /**
     * @return array{string, int}|null
     */
    private function skipTrailers(string $body, int $offset): ?array
    {
        while (true) {
            $lineEnd = strpos($this->buffer, "\r\n", $offset);

            if ($lineEnd === false) {
                if (\strlen($this->buffer) - $offset > $this->maxHeaderBytes) {
                    throw new HttpException(431);
                }

                return null;
            }

            $empty = $lineEnd === $offset;
            $offset = $lineEnd + 2;

            if ($empty) {
                return [$body, $offset];
            }
        }
    }

    /**
     * @return array{string, array<array-key, mixed>}
     */
    private function parseTarget(string $target): array
    {
        if ($target === '*') {
            return ['*', []];
        }

        // Absolute form ("GET http://host/path HTTP/1.1"): keep only the path and the query.
        if (preg_match('#^https?://[^/?\#]*(.*)$#i', $target, $m) === 1) {
            $target = $m[1] !== '' ? $m[1] : '/';
        }

        if (!str_starts_with($target, '/')) {
            throw new HttpException(400, 'Invalid request target');
        }

        [$rawPath, $rawQuery] = explode('?', explode('#', $target, 2)[0], 2) + [1 => ''];

        parse_str($rawQuery, $query);

        return [self::normalizePath($rawPath), $query];
    }

    private function keepAlive(string $protocol, string $connection): bool
    {
        $tokens = array_map(trim(...), explode(',', strtolower($connection)));

        if ($protocol === 'HTTP/1.0') {
            return \in_array('keep-alive', $tokens, true);
        }

        return !\in_array('close', $tokens, true);
    }
}
