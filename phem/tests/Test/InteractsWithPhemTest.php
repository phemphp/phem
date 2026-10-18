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

use Phem\Html;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\Limits;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InteractsWithPhemTest extends KernelTestCase
{
    use InteractsWithPhem;

    // Compiled from tests/Fixtures/app by phemc (PHPStan does not see them).
    private const COUNTER = 'App\Components\Counter';
    private const SIGNUP = 'App\Pages\Signup';

    public static function setUpBeforeClass(): void
    {
        TestKernel::compileSources();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testMountRendersAComponentWithItsProps(): void
    {
        $counter = $this->mount(self::COUNTER, ['label' => 'Hits', 'children' => new Html('<i>!</i>')])
            ->assertSee('Hits 0 times !')
            ->assertSeeHtml('<i>!</i>')
            ->assertSelectorExists('button[data-count="0"]')
            ->assertState('count', 0);

        self::assertStringStartsWith('<phem-c id="phem', $counter->html());
    }

    public function testClickRunsTheHandlerAndMorphsTheComponent(): void
    {
        $this->mount(self::COUNTER, ['label' => 'Hits'])
            ->click('button')
            ->assertSee('Hits 1 times')
            ->assertSelectorExists('button[data-count="1"]')
            ->assertState('count', 1)
            ->click('button')
            ->assertState('count', 2);
    }

    public function testEachEventGetsNewServices(): void
    {
        $counter = $this->mount(self::COUNTER)->click('button')->click('button');
        // The services of the container go into the component; the test can change them.
        $limits = self::getContainer()->get(Limits::class);
        self::assertInstanceOf(Limits::class, $limits);
        $limits->max = 2;

        $counter->click('button')->assertState('count', 2)->assertSee('Clicked 2 times');
    }

    public function testChangeTypesInATextField(): void
    {
        $this->mount(self::SIGNUP)
            ->change('[name=name]', 'Ada')
            ->assertSelectorTextContains('#hello', 'Hello Ada')
            ->assertState('name', 'Ada')
            ->assertSelectorExists('input[name=name][value=Ada]');
    }

    public function testChangeChecksACheckboxAndSelectsAnOption(): void
    {
        $this->mount(self::SIGNUP)
            ->change('[name=agree]', true)
            ->assertSee('agreed, free')
            ->change('[name=plan]', 'pro')
            ->assertSee('agreed, pro')
            ->assertState('plan', 'pro')
            ->change('[name=agree]', false)
            ->assertSee('not agreed, pro');
    }

    public function testKeyAndFocusEvents(): void
    {
        $this->mount(self::SIGNUP)
            ->keyDown('[name=name]', 'Enter')
            ->focus('[name=name]')
            ->assertSee('key Enter, focused');
    }

    public function testSubmitSendsTheFieldsOfTheForm(): void
    {
        $tester = $this->mount(self::SIGNUP, ['title' => 'Join'])
            ->assertSee('Join')
            ->change('[name=name]', 'Ada')
            ->submit('form', ['bio' => 'Mathematician', 'size' => 's', 'tags[]' => ['b']]);

        self::assertSame(['name' => 'Ada', 'plan' => 'free', 'size' => 's', 'tags[]' => 'b', 'bio' => 'Mathematician'], $tester->state()['submitted']);
    }

    public function testASubmitButtonIsAFieldOfTheForm(): void
    {
        $tester = $this->mount(self::SIGNUP)->click('button[value=save]');

        self::assertSame(['name' => '', 'plan' => 'free', 'size' => 'l', 'bio' => '', 'action' => 'save'], $tester->state()['submitted']);
        $tester->assertNoRedirect();
    }

    public function testAHandlerThatRedirects(): void
    {
        $this->mount(self::SIGNUP)
            ->submit('button[value=leave]')
            ->assertRedirect('/about');
    }

    public function testAnElementWithoutAHandlerFails(): void
    {
        $tester = $this->mount(self::SIGNUP);

        $this->assertFails(static fn () => $tester->click('#inert'), 'The element "#inert" has no onClick handler');
        $this->assertFails(static fn () => $tester->click('#off'), 'The element "#off" is disabled');
        $this->assertFails(static fn () => $tester->click('#missing'), 'No element matches "#missing"');
        $this->assertFails(static fn () => $tester->submit('form', ['unknown' => 'x']), 'The form has no field "unknown"');
        $this->assertFails(static fn () => $tester->change('[name=plan]', 'gold'), 'The select "[name=plan]" has no option "gold"');
        $this->assertFails(static fn () => $tester->assertState('count', 1), 'The state has no "count" property');
    }

    public function testVisitLoadsAPageAndSendsItsEventsOverHttp(): void
    {
        $this->visit('/signup')
            ->assertStatus(200)
            ->assertSelectorTextContains('title', 'Signup')
            ->assertSee('Join')
            ->change('[name=name]', 'Grace')
            ->assertStatus(200)
            ->assertSelectorTextContains('#hello', 'Hello Grace')
            ->change('[name=agree]', true)
            ->assertSee('agreed, free');
    }

    public function testVisitAssertsTheStateOfThePage(): void
    {
        $this->visit('/')
            ->assertState('count', 0)
            ->click('button.btn')
            ->assertState('count', 1)
            ->assertSee('Clicked 1 times');
    }

    public function testVisitFollowsTheRedirectOfAHandler(): void
    {
        $this->visit('/leave')
            ->click('#leave')
            ->assertRedirect('/about')
            ->followRedirect()
            ->assertStatus(200)
            ->assertSelectorExists('#about');
    }

    public function testVisitAssertsTheStatusOfAPage(): void
    {
        $this->visit('/private/counter')->assertStatus(403);
        $this->visit('/denied')->assertStatus(403);
    }

    private function assertFails(\Closure $action, string $message): void
    {
        try {
            $action();
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }
        self::fail(\sprintf('The action does not fail with "%s".', $message));
    }
}
