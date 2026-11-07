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

namespace Phem\Tests\Test;

use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * InteractsWithPhem::errorPage(), with the error pages of app/src/Ui/Errors/ErrorPages.phem. The
 * kernel of the tests is in the debug mode, with no `show_exceptions: false`.
 */
final class ErrorPageTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAStatusRendersItsErrorPage(): void
    {
        $this->errorPage(404, '/errors/missing')
            ->assertStatus(404)
            ->assertSelectorTextContains('h1', 'Not here');
    }

    public function testAnExceptionRendersTheErrorPageOfItsStatus(): void
    {
        $this->errorPage(new \RuntimeException('A secret message.'), '/errors/cart?page=2')
            ->assertStatus(500)
            ->assertSelectorTextContains('h1', '500 Internal Server Error')
            ->assertSelectorExists('a#retry[href="/errors/cart?page=2"]')
            ->assertDontSee('A secret message.');
    }

    public function testTheHeadersOfTheExceptionGoToThePage(): void
    {
        $this->errorPage(new TooManyRequestsHttpException(60), '/errors/verify')
            ->assertStatus(429)
            ->assertSelectorTextContains('#retry-after', 'Retry after 60 seconds');
    }

    public function testTheErrorPageHasTheSessionOfTheBrowser(): void
    {
        // An app with sessions, as the error sub-request of Symfony keeps the session.
        self::bootKernel(['environment' => 'errors']);

        $this->errorPage(404, '/errors/session/missing')->assertSelectorTextContains('#session', 'A session');
    }

    public function testVisitShowsTheErrorPageOfAUrlThatFails(): void
    {
        // `show_exceptions: false`, as the e2e tests of an app.
        self::bootKernel(['environment' => 'errors']);

        $this->visit('/errors/missing')
            ->assertStatus(404)
            ->assertSelectorTextContains('h1', 'Not here');

        self::assertSame('web=1', $_SERVER['APP_RUNTIME_MODE'] ?? null, 'the web mode of the runtime, as WebTestCase');
        $this->resetPhemRuntimeMode();
        self::assertArrayNotHasKey('APP_RUNTIME_MODE', $_SERVER);
    }

    public function testWithNoErrorPageThePageOfSymfonyRenders(): void
    {
        $page = $this->errorPage(404, '/missing')->assertStatus(404);

        self::assertStringNotContainsString('Not here', $page->html());
    }
}
