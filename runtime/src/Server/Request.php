<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * An HTTP request, as the Io\Poll server parsed it.
 */
final class Request
{
    /**
     * @param array<string, string> $query   decoded query string
     * @param array<string, string> $headers header names in lowercase
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $remoteAddress = '',
    ) {
    }

    /** The same request, with the address of the client ("ip:port", from the accepted socket). */
    public function withRemoteAddress(string $address): self
    {
        return new self($this->method, $this->path, $this->query, $this->headers, $this->body, $address);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
