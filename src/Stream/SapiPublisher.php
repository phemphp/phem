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

/**
 * Publishes with mercure_publish() in a request of FrankenPHP, and with the HTTP API of the hub
 * in the CLI (a Messenger worker, a command), where mercure_publish() does not work. Phem uses it
 * when the `phem.mercure.publisher_key` option is set.
 */
final class SapiPublisher implements StreamPublisher
{
    public function __construct(
        private readonly StreamPublisher $request,
        private readonly StreamPublisher $cli,
        private readonly string $sapi = \PHP_SAPI,
    ) {
    }

    public function publish(array $topics, string $data, bool $private): void
    {
        ('cli' === $this->sapi ? $this->cli : $this->request)->publish($topics, $data, $private);
    }
}
