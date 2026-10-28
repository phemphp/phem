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
use Phem\Test\Internal\StreamedContent;
use Phem\Tests\Fixtures\Renders;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Error\RuntimeError;

/**
 * The `<Suspense>` boundaries of the /suspense page (see Phem\Rendering\SuspenseRenderer and
 * Phem\Rendering\SuspenseStream).
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

    public function testTheResponseStreamsTheBoundariesThatAreNotLazy(): void
    {
        $page = $this->visit('/suspense')
            ->assertSee('Rows 3')
            ->assertSee('Notes')
            ->assertDontSee('Loading the report…')
            ->assertDontSee('Loading the notes…')
            ->assertSee('Loading the chart…')
            ->assertSelectorExists('#chart phem-suspense[data-phem-lazy]')
            ->assertSelectorExists('#fatal-fallback')
            ->assertSelectorCount(2, 'phem-suspense[data-phem-load]')
            ->assertSelectorNotExists('template')
            ->assertSelectorNotExists('[data-phem-streaming]')
            ->assertState('rows', 3, '#more');

        self::assertSame(1, Renders::of('Report.mount'));
        self::assertStringNotContainsString('chart-error', $page->html());
    }

    public function testTheResponseSendsThePageThenEachBoundaryThenTheState(): void
    {
        $kernel = self::bootKernel();
        $response = $kernel->handle(Request::create('/suspense'));
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $html = StreamedContent::of($response);
        $shell = substr($html, 0, (int) strpos($html, '<template'));
        self::assertStringContainsString('data-phem-streaming', $shell);
        self::assertStringContainsString('Loading the report…', $shell, 'the page shows the fallback first');
        self::assertStringNotContainsString('Rows 3', $shell);
        self::assertStringNotContainsString('data-phem-state-value', $shell, 'the state is not known before the boundaries');

        // The boundary, then the boundary in its children, then the state, before the end of the body.
        self::assertSame(2, preg_match_all('/<template data-phem-for="(phem\w+)">.*?<\/template><script>.*?<\/script>/s', $html, $chunks));
        self::assertStringContainsString('Rows 3', $chunks[0][0]);
        self::assertStringContainsString('Loading the notes…', $chunks[0][0]);
        self::assertStringContainsString('id="notes"', $chunks[0][1]);
        self::assertMatchesRegularExpression('/<template data-phem-root-state data-phem-state-value="[^"]+" data-phem-signature-value="[^"]+"[^>]*><\/template><\/body>/', $html);
        self::assertStringNotContainsString('Loading the report…', $chunks[0][0]);
    }

    public function testTheScriptsOfTheStreamHaveTheNonceOfThePage(): void
    {
        $kernel = self::bootKernel();
        $request = Request::create('/suspense');
        $request->attributes->set('csp_script_nonce', 'n0nce');

        self::assertSame(2, substr_count(StreamedContent::of($kernel->handle($request)), '<script nonce="n0nce">'));
    }

    public function testATurboVisitGetsTheFallbacksAndLoadsTheBoundariesWithAnEvent(): void
    {
        $kernel = self::bootKernel();
        $response = $kernel->handle(Request::create('/suspense', server: ['HTTP_X_TURBO_REQUEST_ID' => 'a1']));
        self::assertNotInstanceOf(StreamedResponse::class, $response);

        $html = (string) $response->getContent();
        self::assertStringContainsString('Loading the report…', $html);
        self::assertStringNotContainsString('Rows 3', $html);
        self::assertStringNotContainsString('<template', $html);
        self::assertSame(0, Renders::of('Report.mount'), 'a slow mount() does not run before the page');

        $this->visit('/suspense', ['HTTP_X_TURBO_REQUEST_ID' => 'a1'])
            ->assertSee('Loading the report…')
            ->load('#report-fallback')
            ->assertSee('Rows 3')
            ->assertState('rows', 3, '#more');
    }

    public function testABoundaryInAFallbackThatWentAwayDoesNotLoad(): void
    {
        $this->visit('/suspense')
            ->assertSee('Rows 3')
            ->assertSelectorNotExists('#hint')
            ->assertSelectorNotExists('#hint-fallback');

        self::assertSame(0, Renders::of('Hint.mount'), 'the fallback of the report went away before the boundary of the hint loaded');
    }

    public function testWithNoStreamTheBrowserLoadsTheBoundariesWithAnEvent(): void
    {
        self::bootKernel(['environment' => 'nostream']);

        $page = $this->visit('/suspense')
            ->assertSee('Loading the report…')
            ->assertDontSee('Rows')
            ->assertSelectorCount(4, 'phem-suspense[data-phem-load]')
            ->assertSelectorNotExists('[data-phem-streaming]');

        self::assertSame(0, Renders::of('Report.mount'), 'a slow mount() does not run before the page');

        $page->load('#report-fallback')
            ->assertStatus(200)
            ->assertSee('Rows 3')
            ->assertSee('Loading the notes…')
            ->assertDontSee('Loading the report…')
            ->assertState('rows', 3, '#more')
            ->load('#notes-fallback')
            ->assertSee('Notes')
            ->click('#more')
            ->assertSee('Rows 4');

        self::assertSame(1, Renders::of('Report.mount'));
    }

    public function testTheStreamedChildrenKeepTheirStateInTheNextEvents(): void
    {
        $this->visit('/suspense')
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
            ->assertSee('Loading the chart…')
            ->load('#chart-fallback')
            ->assertSee('No chart.');
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
        // The stream keeps the fallback, and the browser loads the boundary with an event. The
        // event fails (500): the fallback stays, and phem:error shows.
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('The chart service is down.');

        $this->visit('/suspense')->load('#fatal-fallback');
    }

    public function testLoadWithNoSelectorLoadsAllTheBoundariesInOneEvent(): void
    {
        $page = $this->visit('/suspense');
        $page->document()->querySelector('#fatal')?->remove();

        $page->load()
            ->assertSee('No chart.')
            ->assertSelectorNotExists('phem-suspense');

        self::assertSame(1, Renders::of('Report.mount'));
    }

    public function testABoundaryThatThrowsFailsTheLoadOfAllTheBoundaries(): void
    {
        // In the browser, the load event fails (500): all the fallbacks stay.
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('The chart service is down.');

        $this->visit('/suspense')->load();
    }

    public function testLoadFailsWithNoPendingBoundary(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is not in a <Suspense> that shows its fallback');

        $this->visit('/suspense')->load('#click');
    }
}
