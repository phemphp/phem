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
use Phem\Tests\Fixtures\Renders;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Error\RuntimeError;

/**
 * The `<Suspense>` boundaries of the /suspense page (see Phem\Rendering\SuspenseRenderer).
 */
final class SuspenseTest extends KernelTestCase
{
    use InteractsWithPhem;

    public static function setUpBeforeClass(): void
    {
        TestKernel::compileSources();
    }

    protected function setUp(): void
    {
        Renders::reset();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testThePageShowsTheFallbacksAndDoesNotRenderTheChildren(): void
    {
        $page = $this->visit('/suspense')
            ->assertSee('Loading the report…')
            ->assertSee('Loading the chart…')
            ->assertDontSee('Rows')
            ->assertSelectorCount(3, 'phem-suspense[data-phem-load]')
            ->assertSelectorExists('#chart phem-suspense[data-phem-lazy]')
            ->assertSelectorNotExists('#report phem-suspense[data-phem-lazy]');

        self::assertSame(0, Renders::of('Report.mount'), 'a slow mount() does not run before the load');
        self::assertStringNotContainsString('chart-error', $page->html());
    }

    public function testLoadRendersTheChildrenInPlaceOfTheFallback(): void
    {
        $this->visit('/suspense')
            ->load('#report-fallback')
            ->assertStatus(200)
            ->assertSee('Rows 3')
            ->assertDontSee('Loading the report…')
            ->assertSee('Loading the chart…')
            ->assertState('rows', 3, '#more');

        self::assertSame(1, Renders::of('Report.mount'));
    }

    public function testTheLoadedChildrenKeepTheirStateInTheNextEvents(): void
    {
        $this->visit('/suspense')
            ->load('#report-fallback')
            ->click('#more')
            ->assertSee('Rows 4')
            ->click('#click')
            ->assertSee('Clicks 1')
            ->assertSee('Rows 4')
            ->assertSelectorNotExists('#report-fallback');

        self::assertSame(1, Renders::of('Report.mount'), 'the state keeps the status: the children do not mount again');
    }

    public function testAnEventBeforeTheLoadKeepsTheFallback(): void
    {
        $this->visit('/suspense')
            ->click('#click')
            ->assertSee('Clicks 1')
            ->assertSee('Loading the report…')
            ->load('#report-fallback')
            ->assertSee('Rows 3');
    }

    public function testAThrowingRenderShowsTheErrorSlot(): void
    {
        $page = $this->visit('/suspense')
            ->load('#chart-fallback')
            ->assertStatus(200)
            ->assertSee('No chart.')
            ->assertDontSee('Loading the chart…')
            ->assertSelectorNotExists('#part');

        self::assertStringNotContainsString('Part 1', $page->html(), 'the boundary drops what the children rendered before the exception');

        $page->click('#click')->assertSee('Clicks 1')->assertSee('No chart.');
    }

    public function testAThrowingRenderWithNoErrorSlotFailsTheEvent(): void
    {
        // In the browser, the event fails (500): the fallback stays, and phem:error shows.
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('The chart service is down.');

        $this->visit('/suspense')->load('#fatal-fallback');
    }

    public function testLoadWithNoSelectorLoadsEachBoundaryInOrder(): void
    {
        $page = $this->visit('/suspense');
        $page->document()->querySelector('#fatal')?->remove();

        $page->load()
            ->assertSee('Rows 3')
            ->assertSee('No chart.')
            ->assertSelectorNotExists('phem-suspense');
    }

    public function testLoadFailsWithNoPendingBoundary(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is not in a <Suspense> that shows its fallback');

        $this->visit('/suspense')->load('#click');
    }
}
