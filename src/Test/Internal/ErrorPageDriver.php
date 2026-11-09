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

namespace Phem\Test\Internal;

/**
 * The error page of an error, for a ComponentTester (see InteractsWithPhem::errorPage()). It
 * has no state and no events: its URL is the URL that failed.
 *
 * @internal
 */
final class ErrorPageDriver implements Driver
{
    public function __construct(
        private readonly string $html,
        private readonly int $status,
        private readonly string $url,
    ) {
    }

    public function html(): string
    {
        return $this->html;
    }

    public function isDocument(): bool
    {
        return true;
    }

    public function dispatch(array $handlers, array $event, array $bound = [], array $stale = [], ?array $item = null, array $feeds = []): EventResult
    {
        throw new \LogicException(\sprintf('The error page of "%s" (status %d) has no events: its URL is the URL that failed.', $this->url, $this->status));
    }

    public function state(?string $fiber = null): array
    {
        return [];
    }

    public function url(): string
    {
        return $this->url;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function redirect(): ?string
    {
        return null;
    }
}
