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
use Phem\State\StateCache;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\Limits;
use Phem\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Attribute\RateLimit;

final class InteractsWithPhemTest extends KernelTestCase
{
    use InteractsWithPhem;

    // Compiled from tests/Fixtures/app by phemc (PHPStan does not see them).
    private const COUNTER = 'App\Component\Counter';
    private const SIGNUP = 'App\Page\Signup';
    private const NOTIFIER = 'App\Page\Notifier';
    private const PROFILE = 'App\Page\Profile';
    private const CATALOG = 'App\Page\Catalog';
    private const UPLOADER = 'App\Page\Uploader';

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

        self::assertMatchesRegularExpression('/^<button [^>]*data-phem-c="phem[0-9a-f]{12}">/', $counter->html(), 'the fiber id is on the root element');
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

    public function testLoadingElementHasTheScopeOfItsHandler(): void
    {
        $html = $this->mount(self::COUNTER)->assertSelectorExists('span.spinner[hidden]')->html();

        self::assertSame(1, preg_match('/data-phem-on-click="([^"]+)"/', $html, $click));
        self::assertSame(1, preg_match('/data-phem-loading="([^"]+)"/', $html, $loading));
        self::assertSame($click[1], $loading[1], 'the same id, with no ~2: phem:loading does not register the handler again');
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

    public function testFillSendsTheBoundValuesWithTheNextEvent(): void
    {
        $this->mount(self::PROFILE)
            ->assertSelectorExists('input[name=size][value=s][checked]')
            ->assertSelectorExists('select[name=plan] option[value=free][selected]')
            ->fill('input[name=name]', 'Ada')
            ->fill('input[name=age]', '36')
            ->fill('input[name=agree]', true)
            ->fill('input[name=tag-b]', true)
            ->fill('input[name=size]', 'l')
            ->fill('select[name=plan]', 'pro')
            ->fill('select[name=fields]', ['year'])
            ->fill('textarea[name=bio]', 'Hi')
            ->assertState('name', '')
            ->click('button[type=submit]')
            ->assertSelectorTextContains('#summary', 'Ada, 36, agreed, b, l, pro, year, Hi, saves 1')
            ->assertState('name', 'Ada')
            ->assertState('age', 36)
            ->assertState('tags', ['b'])
            ->assertState('size', 'l')
            ->assertSelectorExists('input[name=name][value=Ada]')
            ->assertSelectorExists('input[name=size][value=l][checked]')
            ->assertSelectorExists('select[name=plan] option[value=pro][selected]');
    }

    public function testABoundValueOfTheWrongTypeGetsTheServerValueBack(): void
    {
        $this->mount(self::PROFILE)
            ->fill('input[name=age]', 'abc')
            ->fill('input[name=name]', 'Ada')
            ->click('button[type=submit]')
            ->assertState('age', null)
            ->assertState('name', 'Ada')
            ->assertSelectorExists('input[name=age][value=""]');
    }

    public function testFillSendsTheValueOfABoundPath(): void
    {
        $this->mount(self::PROFILE)
            ->fill('input[name=status]', 'closed')
            ->fill('input[name=min]', '5')
            ->fill('select[name=ids]', ['3'])
            ->fill('input[name=row-0]', 'Grace')
            ->click('button[type=submit]')
            ->assertState('filters', ['status' => 'closed', 'min' => 5, 'ids' => [3]])
            ->assertState('rows', [['name' => 'Grace'], ['name' => 'Eve']])
            ->assertSelectorTextContains('#filters', 'closed, 5, 3, Grace+Eve');
    }

    public function testChangeOfALiveFieldSendsAnEventWithNoHandler(): void
    {
        $this->mount(self::PROFILE)
            ->change('input[name=query]', 'php')
            ->assertSee('Results for php')
            ->assertState('saves', 0);

        $this->visit('/bind')
            ->fill('input[name=name]', 'Ada')
            ->change('input[name=query]', 'php')
            ->assertSee('Results for php')
            ->assertSelectorTextContains('#summary', 'Ada,')
            ->click('button[type=submit]')
            ->assertSelectorTextContains('#summary', 'saves 1');
    }

    public function testABoundFieldInTheSlotOfAnotherComponent(): void
    {
        $this->visit('/bind-page')
            ->change('input[name=name]', 'Ada')
            ->assertSelectorTextContains('#hello', 'Hello Ada')
            ->assertState('name', 'Ada');
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

    /** As the browser, visit() sends only the signature, and the state again on a miss of the cache. */
    public function testVisitSendsTheStateWhenTheCacheDoesNotHaveIt(): void
    {
        $tester = $this->visit('/')->click('button.btn')->assertState('count', 1);

        $pool = static::getContainer()->get(StateCache::POOL);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        $tester->click('button.btn')
            ->assertState('count', 2) // one click, also with the second request
            ->click('button.btn')
            ->assertState('count', 3);
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

    public function testVisitFollowsARedirectToAPage(): void
    {
        $this->visit('/go')
            ->click('#item')
            ->assertRedirect('/items/5?tab=notes')
            ->followRedirect()
            ->assertSelectorExists('#item');
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

    public function testVisitWritesTheUrlPropertiesIntoTheUrl(): void
    {
        $tester = $this->visit('/url?q=php&page=abc&x=1')
            ->assertSee('q=php, page=1')
            ->assertQuery('q', 'php')
            ->assertQuery('page', null)
            ->assertQuery('x', '1');
        self::assertSame('/url?q=php&x=1', $tester->url());

        $tester->click('#next')
            ->click('#tab')
            ->fill('[name=q]', 'a b')
            ->click('#click')
            ->assertSee('q=a b, page=2')
            ->assertQuery('page', '2')
            ->assertQuery('tab', 'new');
        self::assertSame('/url?q=a%20b&x=1&page=2&tab=new', $tester->url());

        $tester->click('#clear')->assertQuery('q', null);
        self::assertSame('/url?x=1&tab=new', $tester->url());
        $this->assertFails(static fn () => $tester->assertQuery('tab', 'all'), 'The "tab" key in the query string of "/url?x=1&tab=new"');
    }

    public function testMountWritesTheUrlPropertiesIntoTheUrl(): void
    {
        $tester = $this->mount(self::CATALOG)->assertQuery('page', null);
        self::assertSame('/', $tester->url());

        $tester->click('#next')->assertQuery('page', '2');
        self::assertSame('/?page=2', $tester->url());
    }

    public function testUploadSendsTheFilesWithTheNextSubmit(): void
    {
        [$a, $b, $c] = self::files(['a.txt' => 'alpha', 'b.txt' => 'beta', 'c.txt' => 'gamma']);
        $open = ['mount' => fn () => $this->mount(self::UPLOADER), 'visit' => fn () => $this->visit('/upload')];

        foreach ($open as $how => $tester) {
            $tester()
                ->upload('input[name=photo]', $a)
                ->upload('input[name="photos[]"]', [$b, $c])
                ->upload('input[name="doc[file]"]', $c)
                ->submit()
                ->assertState('uploaded', ['a.txt 5 alpha', 'b.txt 4 beta', 'c.txt 5 gamma', 'c.txt 5 gamma'], in: 'form')
                ->assertSelectorTextContains('#request', 'Notes a.txt');

            $tester()->submit()->assertState('uploaded', [], in: 'form');
            $tester()->upload('input[name=photo]', $a)->submit()->submit()->assertState('uploaded', ['a.txt 5 alpha'], in: 'form');
            $tester()->upload('input[name=photo]', $a)->upload('input[name=photo]', [])->submit()->assertState('uploaded', [], in: 'form');
            $tester()->upload('input[name=photo]', $a)->click('button[value=move]')->assertSelectorTextContains('#request', 'moved');
            self::assertFileExists($a, $how.': the handler moves a copy of the file');
        }
    }

    public function testUploadFailsForAnInputThatTakesNoSuchFiles(): void
    {
        [$a, $b] = self::files(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $tester = $this->mount(self::UPLOADER);

        $this->assertFails(static fn () => $tester->upload('input[name=title]', $a), 'The element "input[name=title]" is not a file input.');
        $this->assertFails(static fn () => $tester->upload('input[name=photo]', [$a, $b]), 'has no "multiple" attribute');
        $this->assertFails(static fn () => $tester->upload('input[name=off]', $a), 'is disabled');
        $this->assertFails(static fn () => $tester->upload('input[name=photo]', $a.'.missing'), 'does not exist');
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

    /**
     * @param array<string, string> $contents file name => content
     *
     * @return list<string> the paths, in a new directory
     */
    private static function files(array $contents): array
    {
        $dir = sys_get_temp_dir().'/'.uniqid('phem-upload-');
        mkdir($dir);

        $paths = [];
        foreach ($contents as $name => $content) {
            file_put_contents($paths[] = $dir.'/'.$name, $content);
        }

        return $paths;
    }
}
