<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Stream;

use Psr\Log\LoggerInterface;

/**
 * Publishes to the Mercure hub of FrankenPHP with mercure_publish(): no HTTP request and no
 * publisher token. It needs a request of FrankenPHP (also in worker mode), with the `mercure`
 * directive in the same site of the Caddyfile.
 */
final class FrankenPhpPublisher implements StreamPublisher
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Phem publishes at the end of the request, after the handler committed: an error does not
     * fail the request, the logger gets it.
     */
    public function publish(array $topics, string $data, bool $private): void
    {
        if (!\function_exists('mercure_publish')) {
            $this->logger?->error('Phem publishes the streams with the Mercure hub of FrankenPHP, but mercure_publish() does not exist: run the application with FrankenPHP, with the `mercure` directive in its Caddyfile. In a worker or a command, set the phem.mercure.publisher_key option. The streams to {topics} are lost.', ['topics' => implode(', ', $topics)]);

            return;
        }

        try {
            mercure_publish($topics, $data, $private);
        } catch (\Throwable $e) {
            $this->logger?->error('Phem could not publish the streams to {topics}: {message}', ['topics' => implode(', ', $topics), 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
