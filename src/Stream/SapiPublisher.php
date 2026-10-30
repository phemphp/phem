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
 * in another SAPI, where mercure_publish() does not exist or does not work: a request of PHP-FPM
 * (with a standalone hub), or the CLI (a Messenger worker, a command, also `frankenphp php-cli`).
 * Phem uses it when the `phem.mercure.publisher_key` option is set.
 */
final class SapiPublisher implements StreamPublisher
{
    public function __construct(
        private readonly StreamPublisher $frankenphp,
        private readonly StreamPublisher $hub,
        private readonly string $sapi = \PHP_SAPI,
    ) {
    }

    public function publish(array $topics, string $data, bool $private): void
    {
        ('frankenphp' === $this->sapi ? $this->frankenphp : $this->hub)->publish($topics, $data, $private);
    }
}
