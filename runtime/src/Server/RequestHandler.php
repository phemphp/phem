<?php

declare(strict_types=1);

namespace Rephp\Server;

/**
 * The application side of the server. Rephp\App implements it.
 * It returns null when it does not handle the request (the server then sends 404).
 */
interface RequestHandler
{
    public function handle(Request $request): ?Response;
}
