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

namespace Phem\Tests\Runtime;

use Phem\Event;
use Phem\Handler\RateLimitMiddleware;
use Phem\Tests\Fixtures\ClosureKeyLimited;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitMiddlewareTest extends TestCase
{
    #[RequiresPhp('>= 8.5.0')]
    public function testAClosureKeyGetsTheRequestAndTheComponent(): void
    {
        $limiters = new RateLimiterFactory(['id' => 'phem_events', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage());
        $requests = new RequestStack();
        $requests->push(Request::create('/limited', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']));
        $middleware = new RateLimitMiddleware(new ServiceLocator(['phem_events' => static fn (): RateLimiterFactory => $limiters]), requests: $requests);
        $component = new ClosureKeyLimited();

        self::assertSame('ran', $middleware->handle($component->like(...), new Event('click'), static fn (): string => 'ran'));
        self::assertFalse($limiters->create('ip-10.0.0.1~'.ClosureKeyLimited::class)->consume()->isAccepted(), 'the closure gives the key');

        $this->expectException(TooManyRequestsHttpException::class);
        $middleware->handle($component->like(...), new Event('click'), static fn (): string => 'ran');
    }
}
