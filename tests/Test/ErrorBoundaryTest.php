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

/**
 * The `<ErrorBoundary>` of the /error-boundary page (see Phem\Rendering\ErrorBoundaryRenderer).
 */
final class ErrorBoundaryTest extends KernelTestCase
{
    use InteractsWithPhem;

    public static function setUpBeforeClass(): void
    {
        TestKernel::compileSources();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testThePageRendersTheChildrenWhenTheyDoNotThrow(): void
    {
        $this->visit('/error-boundary')
            ->assertSee('Quote 42')
            ->assertSee('Ticks 1')
            ->assertSelectorNotExists('#card-fallback')
            ->assertSelectorNotExists('#other-fallback')
            ->assertSelectorNotExists('phem-suspense');
    }

    public function testAThrowingRenderShowsTheFallbackOnlyForItsBoundary(): void
    {
        $page = $this->visit('/error-boundary')
            ->click('#toggle')
            ->assertStatus(200)
            ->assertSee('No quote.')
            ->assertSelectorNotExists('#up')
            ->assertSelectorTextContains('#other', 'Quote 42');

        self::assertStringNotContainsString('Ticks', $page->html(), 'the boundary drops what the children rendered before the exception');

        $page->click('#click')->assertSee('Clicks 1')->assertSee('No quote.');
    }

    public function testTheBoundaryTriesTheChildrenAgainOnEachRender(): void
    {
        $this->visit('/error-boundary')
            ->click('#up')
            ->assertSee('Ticks 2')
            ->click('#toggle')
            ->assertSee('No quote.')
            ->click('#toggle')
            ->assertSee('Quote 42')
            ->assertSelectorNotExists('#card-fallback')
            ->assertSee('Ticks 1')
            ->assertState('n', 1, '#up');
    }

    public function testAnExceptionInAHandlerFailsTheEvent(): void
    {
        // In the browser, the event fails (500): the transaction rolls back, and the page keeps its state.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The handler failed.');

        $this->visit('/error-boundary')->click('#fail');
    }
}
