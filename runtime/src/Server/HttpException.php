<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * A request that the server rejects before it reaches the handler (400, 413, 431, 501, 505).
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : ResponseSerializer::reason($status), $status);
    }
}
