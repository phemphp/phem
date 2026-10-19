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

use Phem\State\StateCodec;
use Phem\Tests\Fixtures\Renders;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

/**
 * An event renders only the components that can change (see Phem\Rendering\EventRenderer).
 *
 * With no `kernel.debug`: the dev-mode check renders the full page again (see SkippedRenderCheckTest).
 */
final class SkipTest extends TestCase
{
    use FixtureApp;
    use SkipPage;

    public function fixtureKernel(): TestKernel
    {
        if (null === $this->kernel) {
            TestKernel::compileSources();
            $this->kernel = new TestKernel('nodebug', false);
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    public function testAClickInAComponentRendersOnlyThatComponent(): void
    {
        $data = $this->click('clicker');

        self::assertSame(0, Renders::of('Probe'), 'the other components are kept, in the two passes');
        self::assertSame(2, Renders::of('Clicker'), 'the first pass gives the handler, the second pass shows the change');
        self::assertSame(['Clicks 1'], $this->texts($data['streams']));
        self::assertStringNotContainsString('data-phem-keep', $data['streams']);
    }

    public function testAStateChangeRendersTheChildrenWithNewProps(): void
    {
        $data = $this->click('bump');

        self::assertSame(1, Renders::of('Probe'), 'only the probe of "n": the other probes get the same props');
        self::assertSame(0, Renders::of('Clicker'));
        self::assertStringContainsString('Bump 1', $data['streams']);
        self::assertStringContainsString('<li>Probe 1</li>', $data['streams'], 'the probe of "n" renders, in the root');
        self::assertSame(23, substr_count($data['streams'], 'data-phem-keep></phem-c>'), 'a placeholder for each kept component: the 20 probes of the list, Clicker, Note and Impure (Mounted and Reloader render)');
    }

    public function testTheOwnerOfTheHandlerRendersAlsoWithNoStateChange(): void
    {
        $data = $this->click('note');

        self::assertSame(['Note written'], $this->texts($data['streams']), 'a plain property that the handler set (as a form with its errors)');
    }

    public function testAComponentWithNoStateAndAMountRendersWhenItsParentRenders(): void
    {
        Renders::$external = 7;

        $data = $this->click('bump');

        self::assertStringContainsString('Mounted 7', $data['streams']);
        self::assertStringNotContainsString('Impure 7', $data['streams'], 'a pure component with the same props is kept');
    }

    public function testAStateWithNoShapeRendersTheFullPageAndUpdatesOnlyTheChanges(): void
    {
        // A page that the browser loaded before the shape was in the state (for example before a deploy).
        $codec = $this->service('phem.state_codec', StateCodec::class);
        $data = json_decode($this->state, true);
        unset($data['_tree']);
        [$this->state, $this->signature] = $codec->encode($data, 'App\Pages\SkipPage');

        $data = $this->click('clicker');

        self::assertSame(42, Renders::of('Probe'), 'the full page, in the two passes');
        self::assertSame(['Clicks 1'], $this->texts($data['streams']), 'it compares with the first pass');
        self::assertArrayHasKey('_tree', json_decode($data['state'], true), 'the next event has the shape');
    }

    public function testMountRunsAgainInTheRenderAfterTheHandler(): void
    {
        $data = $this->click('reloader');

        self::assertSame(['Reloader 1'], $this->texts($data['streams']), 'the instance of the first pass reads the new data');
    }

    public function testRefreshRendersTheFullPage(): void
    {
        $data = $this->click('refresh');

        self::assertSame(21, Renders::of('Probe'), 'the first pass renders the path to the handler, the second pass the full page');
        self::assertStringContainsString('Impure 1', $data['streams']);
        self::assertStringContainsString('Mounted 1', $data['streams']);
        self::assertStringNotContainsString('data-phem-keep', $data['streams']);
    }

    public function testTheStateOfTheKeptComponentsStays(): void
    {
        $this->click('clicker');
        $data = $this->click('bump');
        $data = $this->click('clicker');

        self::assertSame(['Clicks 2'], $this->texts($data['streams']), 'the state of Clicker was kept while the page rendered');
        self::assertStringNotContainsString('Bump', $data['streams']);
    }
}
