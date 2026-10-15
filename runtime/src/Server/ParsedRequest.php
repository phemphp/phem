<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * A Request plus the connection details that only the server needs.
 */
final readonly class ParsedRequest
{
    public function __construct(
        public Request $request,
        public string $protocol,
        public bool $keepAlive,
    ) {
    }
}
