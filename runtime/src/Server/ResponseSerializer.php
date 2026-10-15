<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * Turns a Response into the bytes of an HTTP/1.1 response.
 */
final class ResponseSerializer
{
    private const array REASONS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        505 => 'HTTP Version Not Supported',
    ];

    /** The server sets these headers itself. */
    private const array RESERVED = ['content-length', 'connection', 'date', 'transfer-encoding'];

    public static function reason(int $status): string
    {
        return self::REASONS[$status] ?? match (intdiv($status, 100)) {
            1 => 'Informational',
            2 => 'Success',
            3 => 'Redirection',
            4 => 'Client Error',
            default => 'Server Error',
        };
    }

    /**
     * 1xx, 204 and 304 responses never have a body. A HEAD response has the headers only.
     */
    public static function sendsBody(int $status, bool $head): bool
    {
        return !$head && $status >= 200 && $status !== 204 && $status !== 304;
    }

    public static function serialize(Response $response, bool $head, bool $keepAlive): string
    {
        $status = $response->status;
        $hasContent = $status >= 200 && $status !== 204 && $status !== 304;

        $lines = [\sprintf('HTTP/1.1 %d %s', $status, self::reason($status))];
        $lines[] = 'Date: '.gmdate('D, d M Y H:i:s').' GMT';
        $lines[] = 'Connection: '.($keepAlive ? 'keep-alive' : 'close');

        if ($hasContent) {
            $lines[] = 'Content-Length: '.\strlen($response->body);
        }

        $hasServer = false;

        foreach ($response->headers as $name => $values) {
            $name = self::clean((string) $name);
            $lower = strtolower($name);

            if ($name === '' || \in_array($lower, self::RESERVED, true)) {
                continue;
            }

            $hasServer = $hasServer || $lower === 'server';

            foreach ((array) $values as $value) {
                $lines[] = $name.': '.self::clean((string) $value);
            }
        }

        if (!$hasServer) {
            $lines[] = 'Server: rephp';
        }

        $raw = implode("\r\n", $lines)."\r\n\r\n";

        return self::sendsBody($status, $head) ? $raw.$response->body : $raw;
    }

    /**
     * Removes CR and LF, so a header value cannot inject other headers.
     */
    private static function clean(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
