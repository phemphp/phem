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

namespace Phem\Http;

use Phem\Manifest\Manifest;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Checks the `#[ErrorPage]` attributes (see ErrorPages::check()), so an attribute that is not
 * valid fails the build, not the first error in production. It is not optional: Symfony runs it
 * after each build of the container (in the debug mode, a new phemc build) and in
 * `cache:warmup`.
 */
final class ErrorPagesWarmer implements CacheWarmerInterface
{
    public function __construct(
        private readonly ErrorPages $pages,
        private readonly Manifest $manifest,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->manifest->exists()) {
            $this->pages->check();
        }

        return [];
    }
}
