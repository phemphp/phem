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
use Symfony\Component\HttpKernel\Attribute\RateLimit;

final class InteractsWithPhemTest extends KernelTestCase
{
    use InteractsWithPhem;

    // Compiled from tests/Fixtures/app by phemc (PHPStan does not see them).
    private const COUNTER = 'App\Components\Counter';
    private const SIGNUP = 'App\Pages\Signup';
    private const NOTIFIER = 'App\Pages\Notifier';

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

    public function testAssertStateOfAChildComponent(): void
    {
        $tester = $this->visit('/')
            ->click('button[data-count]')
            ->assertState('count', 1, in: 'button[data-count]')
            ->assertState('count', 0)
            ->assertState('count', 0, in: '#add')
            ->assertState('count', 0, in: 'title')
            ->click('#toggle')
            ->assertState('on', true, in: '#toggle')
            ->assertState('on', true, in: '#yes');

        self::assertSame(['on' => true], $tester->state(in: '#toggle'));
        $this->assertFails(static fn () => $tester->assertState('count', 1, in: '#toggle'), 'The state of the component around "#toggle" has no "count" property');
        $this->assertFails(static fn () => $tester->assertState('on', false, in: '#toggle'), 'The "on" state property of the component around "#toggle"');
        $this->assertFails(static fn () => $tester->assertState('on', true, in: '#missing'), 'No element matches "#missing"');
    }

    public function testMountAssertsTheStateOfAnElementOfTheComponent(): void
    {
        $this->mount(self::COUNTER)
            ->click('button')
            ->assertState('count', 1, in: 'button');
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

    public function testVisitSeesTheRateLimitOfThePage(): void
    {
        if (!class_exists(RateLimit::class)) {
            self::markTestSkipped('The #[RateLimit] attribute needs Symfony 8.1.');
        }
        // The limit of the fixture app is 2 events in a minute: the third click does not run.
        $this->visit('/limited')
            ->click('button')
            ->click('button')
            ->assertState('count', 2)
            ->click('button')
            ->assertStatus(429)
            ->assertState('count', 2);
    }

    public function testAssertDispatchedChecksTheBrowserEventsOfTheLastAction(): void
    {
        $tester = $this->mount(self::NOTIFIER)
            ->click('#save')
            ->assertDispatched('toast')
            ->assertDispatched('toast', ['message' => 'Saved 1'])
            ->assertDispatched('saved', 1)
            ->assertNotDispatched('clicked');

        $this->assertFails(static fn () => $tester->assertDispatched('clicked'), 'The last action did not dispatch the browser event "clicked" (it dispatched "toast", "saved")');
        $this->assertFails(static fn () => $tester->assertDispatched('saved', 2), 'The detail of the browser event "saved"');
        $this->assertFails(static fn () => $tester->assertNotDispatched('toast'), 'The last action dispatched the browser event "toast"');

        // Each action starts with no browser events.
        $tester->click('#quiet')->assertNotDispatched('toast');
        $this->assertFails(static fn () => $tester->assertDispatched('toast'), 'The last action did not dispatch the browser event "toast".');
    }

    public function testAssertDispatchedSeesTheEventsOfEachHandlerOfAnAction(): void
    {
        $this->mount(self::NOTIFIER)
            ->click('#send')
            ->assertDispatched('clicked', null)
            ->assertDispatched('submitted', ['action' => 'send']);
    }

    public function testAHandlerThatRedirectsDispatchesNoBrowserEvents(): void
    {
        $this->mount(self::NOTIFIER)->click('#leave')->assertRedirect('/about')->assertNotDispatched('toast');
        $this->visit('/notify')->click('#leave')->assertRedirect('/about')->assertNotDispatched('toast');
    }

    public function testVisitGetsTheBrowserEventsOverHttp(): void
    {
        $this->visit('/notify')
            ->click('#save')
            ->assertSee('Save 1')
            ->assertDispatched('toast', ['message' => 'Saved 1'])
            ->assertDispatched('saved', 1)
            ->click('#send')
            ->assertDispatched('submitted', ['action' => 'send'])
            ->assertNotDispatched('toast');
    }

    public function testAHandlerDispatchesWithTheInjectedDispatcher(): void
    {
        $this->mount(self::NOTIFIER)
            ->click('#announce')
            ->assertDispatched('toast', ['message' => 'Announced 1'])
            ->assertDispatched('announced', 'Hello')
            ->click('#quiet')
            ->assertNotDispatched('toast')
            ->assertNotDispatched('announced');
        $this->visit('/notify')
            ->click('#announce')
            ->assertDispatched('toast', ['message' => 'Announced 1'])
            ->assertDispatched('announced', 'Hello')
            ->click('#quiet')
            ->assertNotDispatched('announced');
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
