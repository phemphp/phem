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

namespace Phem\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\RateLimit;

/**
 * A handler with a Closure key in its `#[RateLimit]`. A closure in an attribute needs PHP 8.5, so
 * only RateLimitMiddlewareTest loads this class, on PHP 8.5.
 */
final class ClosureKeyLimited
{
    #[RateLimit('phem_events', key: static function (array $args, ?Request $request, object $component): string {
        return 'ip-'.$request?->getClientIp().'~'.$component::class;
    })]
    public function like(): void
    {
    }
}
