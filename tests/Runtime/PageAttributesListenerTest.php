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

use Phem\Attribute\Page;
use Phem\EventListener\PageAttributesListener;
use Phem\Tests\Fixtures\PathPages\Paged;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PageAttributesListenerTest extends TestCase
{
    public function testThePageGivesItsControllerAttributesButNotItsRoutes(): void
    {
        $event = self::event(Paged::class);

        (new PageAttributesListener())->onKernelController($event);

        $cache = $event->getAttributes(Cache::class);
        self::assertCount(1, $cache);
        self::assertSame(30, $cache[0]->maxage);
        self::assertSame([], $event->getAttributes(Page::class));
        self::assertSame([], $event->getAttributes(Route::class));
    }

    public function testAFunctionPageGivesItsControllerAttributes(): void
    {
        $event = self::event('Phem\\Tests\\Fixtures\\PathPages\\FunctionPage');

        (new PageAttributesListener())->onKernelController($event);

        $cache = $event->getAttributes(Cache::class);
        self::assertCount(1, $cache);
        self::assertSame(60, $cache[0]->maxage);
        self::assertSame([], $event->getAttributes(Page::class));
    }

    private static function event(string $page): ControllerEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new ControllerEvent($kernel, static fn (): Response => new Response(), new Request(attributes: ['_phem_page' => $page]), HttpKernelInterface::MAIN_REQUEST);
    }
}
