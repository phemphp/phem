<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * An HTTP response that a RequestHandler returns.
 */
final class Response
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }

    public static function turboStream(string $body): self
    {
        return new self(200, ['Content-Type' => 'text/vnd.turbo-stream.html; charset=UTF-8'], $body);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=UTF-8'], $body);
    }

    public static function notFound(): self
    {
        return self::text("Not Found\n", 404);
    }
}
