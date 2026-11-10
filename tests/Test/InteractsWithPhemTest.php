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
use Phem\Test\ComponentTester;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\Limits;
use Phem\Tests\Fixtures\TestKernel;
use Phem\ToastType;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Attribute\RateLimit;

final class InteractsWithPhemTest extends KernelTestCase
{
    use InteractsWithPhem;

    // Compiled from tests/Fixtures/app by phemc (PHPStan does not see them).
    private const COUNTER = 'App\Ui\Common\Counter';
    private const SIGNUP = 'App\Ui\Signup\Signup';
    private const NOTIFIER = 'App\Ui\Notify\Notifier';
    private const PROFILE = 'App\Ui\Bind\Profile';
    private const CATALOG = 'App\Ui\Url\Catalog';
    private const UPLOADER = 'App\Ui\Upload\Uploader';
    private const DROP_ZONE = 'App\Ui\Upload\DropZone';

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

    public function testVisitFollowsTheRedirectOfAPageMount(): void
    {
        $this->visit('/guard/closed')
            ->assertRedirect('/items/7')
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

    public function testVisitOpensAUrlInTheSameBrowser(): void
    {
        $first = $this->visit('/cookies?set=theme=dark', ['HTTP_ACCEPT_LANGUAGE' => 'es']);
        self::assertSame('none', self::sent($first));
        self::assertSame(['theme' => 'dark'], $first->cookies());

        // The cookie of the first page, and its server values, go to the next page of the browser.
        $second = $first->visit('/cookies')->assertSelectorTextContains('#language', 'es');
        self::assertSame('theme=dark', self::sent($second));

        // The two pages share the cookies: a cookie of the second page goes with the events of the first page.
        $second->visit('/cookies?set=size=xl');
        self::assertSame(['size' => 'xl', 'theme' => 'dark'], $first->cookies());
        self::assertSame('size=xl theme=dark', self::sent($first->click('#again')));

        $first->visit('/cookies?clear=theme');
        self::assertSame('size=xl', self::sent($second->visit('/cookies')));
        self::assertSame(['size' => 'xl'], $second->cookies());

        // A new browser has no cookies.
        $other = $this->visit('/cookies');
        self::assertSame('none', self::sent($other));
        self::assertSame([], $other->cookies());

        $this->assertFails(fn () => $this->mount(self::COUNTER)->visit('/cookies'), 'A mounted component has no browser');
        $this->assertFails(fn () => $this->mount(self::COUNTER)->cookies(), 'A mounted component has no browser');
    }

    public function testVisitGivesCookiesToTheBrowser(): void
    {
        $page = $this->visit('/cookies', cookies: ['theme' => 'dark']);
        self::assertSame('theme=dark', self::sent($page));

        // The next pages of the browser get them too, with the cookies that they add.
        self::assertSame('size=xl theme=dark', self::sent($page->visit('/cookies/admin', ['size' => 'xl'])));
        self::assertSame('size=xl theme=dark', self::sent($page->click('#again')));

        // A given cookie replaces the cookie of a response on its host and the path "/", and a response replaces a given cookie.
        $page->visit('/cookies?set=theme=light')->visit('/cookies', ['theme' => 'blue']);
        self::assertSame(['size' => 'xl', 'theme' => 'blue'], $page->cookies());
        self::assertSame(['size' => 'xl', 'theme' => 'light'], $page->visit('/cookies?set=theme=light')->cookies());

        // A cookie of a response with a longer path stays, and wins on its path.
        $page->visit('/cookies?set=size=admin&path=/cookies/admin')->visit('/cookies', ['size' => 's']);
        self::assertSame('size=s theme=light', self::sent($page->visit('/cookies')));
        self::assertSame('size=admin theme=light', self::sent($page->visit('/cookies/admin')));

        // A given cookie goes to the host of the URL only.
        $page = $this->visit('http://shop.example/cookies', cookies: ['host' => 'only']);
        self::assertSame('host=only', self::sent($page));
        self::assertSame('none', self::sent($page->visit('http://www.shop.example/cookies')));
    }

    public function testVisitChecksTheGivenCookies(): void
    {
        // As in a browser, a __Secure- or a __Host- cookie goes on HTTPS and on localhost only.
        $page = $this->visit('http://shop.example/cookies', cookies: ['__Secure-token' => '1', '__Host-id' => '2']);
        self::assertSame('none', self::sent($page));
        self::assertSame('__Host-id=2 __Secure-token=1', self::sent($page->visit('https://shop.example/cookies')));
        self::assertSame('__Host-id=2 __Secure-token=1', self::sent($this->visit('/cookies', cookies: ['__Secure-token' => '1', '__Host-id' => '2'])));

        $this->assertThrows(fn () => $this->visit('/cookies', cookies: ['count' => 1]), 'The value of the cookie "count" is "int": give a string.'); // @phpstan-ignore argument.type
        $this->assertThrows(fn () => $this->visit('/cookies')->visit('/cookies', ['' => 'x']), 'A cookie cannot have the name ""');
    }

    public function testVisitOpensARelativeUrlFromThePage(): void
    {
        $page = $this->visit('http://shop.example/cookies/admin', cookies: ['host' => 'shop']);

        // As a link of the page: on its scheme and its host, and on its path.
        self::assertSame('http://shop.example/cookies', $page->visit('/cookies')->url());
        self::assertSame('b=1 host=shop', self::sent($page->visit('/cookies', ['b' => '1'])));
        self::assertSame('http://shop.example/cookies/admin', $page->visit('admin')->url());
        self::assertSame('http://shop.example/cookies/admin?set=a=1', $page->visit('?set=a=1')->url());
        self::assertSame('https://www.shop.example/cookies', $this->visit('https://shop.example/cookies')->visit('//www.shop.example/cookies')->url());

        // The relative redirect of a handler opens on the host of the page.
        $about = $this->visit('http://shop.example/leave')->click('#leave')->assertRedirect('/about')->followRedirect();
        $about->assertStatus(200)->assertSelectorExists('#about');
        self::assertSame('http://shop.example/about', $about->url());

        // A page with a relative URL stays on the host of Request::create().
        self::assertSame('/cookies/admin', $this->visit('/cookies/admin')->visit('admin')->url());
    }

    public function testVisitSendsACookieOnlyToItsHostAndPath(): void
    {
        $page = $this->visit('/cookies?set=area=admin&path=/cookies/admin');
        self::assertSame('none', self::sent($page->visit('/cookies')));
        self::assertSame([], $page->visit('/cookies')->cookies());
        self::assertSame('area=admin', self::sent($page->visit('/cookies/admin')));
        self::assertSame(['area' => 'admin'], $page->visit('/cookies/admin')->cookies());

        // A cookie with no Domain goes to its host only. A cookie with a Domain goes to its subdomains too.
        $page = $this->visit('http://shop.example/cookies?set=host=only');
        self::assertSame('host=only', self::sent($page->visit('http://shop.example/cookies')));
        self::assertSame('none', self::sent($page->visit('http://www.shop.example/cookies')));
        self::assertSame([], $page->visit('http://www.shop.example/cookies')->cookies());
        $page->visit('http://shop.example/cookies?set=domain=all&domain=shop.example');
        self::assertSame('domain=all', self::sent($page->visit('http://www.shop.example/cookies')));

        // An empty Domain (the default session.cookie_domain of PHP) is no Domain.
        $page = $this->visit('/cookies?set=session=1&domain=');
        self::assertSame('session=1', self::sent($page->visit('/cookies')));
        self::assertSame(['session' => '1'], $page->visit('/cookies')->cookies());
        $page = $this->visit('http://shop.example/cookies?set=session=1&domain=');
        self::assertSame('none', self::sent($page->visit('http://www.shop.example/cookies')));

        // A Secure cookie goes on HTTPS and on localhost only.
        $page = $this->visit('http://shop.example/cookies?set=token=1&secure=1');
        self::assertSame('none', self::sent($page->visit('http://shop.example/cookies')));
        self::assertSame('token=1', self::sent($page->visit('https://shop.example/cookies')));
        self::assertSame(['token' => '1'], $page->visit('https://shop.example/cookies')->cookies());
        self::assertSame('token=1', self::sent($this->visit('/cookies?set=token=1&secure=1')->visit('/cookies')));
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

    public function testAssertToastChecksTheToastsOfTheLastAction(): void
    {
        $tester = $this->mount(self::NOTIFIER)
            ->click('#toast')
            ->assertToast('Saved')
            ->assertToast('Saved', ToastType::Success);

        $this->assertFails(static fn () => $tester->assertToast('Saved', ToastType::Error), 'The last action did not show the toast error "Saved" (it showed success "Saved").');
        $this->assertFails(static fn () => $tester->assertToast('Gone'), 'The last action did not show the toast "Gone" (it showed success "Saved").');

        // A toast of the dispatcher is an info toast.
        $tester->click('#save')->assertToast('Saved 1', ToastType::Info);

        $tester->click('#quiet');
        $this->assertFails(static fn () => $tester->assertToast('Saved 1'), 'The last action did not show the toast "Saved 1".');
    }

    public function testAssertToastReadsTheDetailAsTheController(): void
    {
        $tester = $this->mount(self::NOTIFIER)
            ->click('#raw')
            // A string detail is the message.
            ->assertToast('Plain', ToastType::Info)
            // The controller shows a type that it does not know as info.
            ->assertToast('Odd', ToastType::Info)
            ->assertToast('["a","b"]');

        // A toast with no message does not show.
        $this->assertFails(static fn () => $tester->assertToast(''), 'The last action did not show the toast "" (it showed info "Plain", info "Odd", info "["a","b"]").');
    }

    public function testAHandlerShowsAToastWithTheInjectedService(): void
    {
        $this->mount(self::NOTIFIER)
            ->click('#remind')
            ->assertToast('Check the stock', ToastType::Warning)
            ->assertDispatched('toast', ['message' => 'Check the stock', 'type' => 'warning', 'duration' => 0]);

        $this->visit('/notify')
            ->click('#remind')
            ->assertToast('Check the stock', ToastType::Warning);
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

    public function testDropAndPasteSendTheirFilesAsAList(): void
    {
        [$a, $b] = self::files(['a.png' => 'alpha', 'b.png' => 'beta']);
        $open = ['mount' => fn () => $this->mount(self::DROP_ZONE), 'visit' => fn () => $this->visit('/upload')];

        foreach ($open as $tester) {
            $tester()->drop('#zone', [$a, $b])->assertState('received', ['drop a.png alpha', 'drop b.png beta'], in: '#zone');
            $tester()->paste('textarea', $b)->assertState('received', ['paste b.png beta'], in: '#zone');
            $tester()->drop('#zone')->assertState('received', [], in: '#zone');
            $tester()->paste('textarea', text: 'https://example.com')->assertState('received', ['paste text https://example.com'], in: '#zone');
        }
    }

    public function testClearFilesEmptiesTheFileInputsOfTheForm(): void
    {
        [$a, $b] = self::files(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $open = ['mount' => fn () => $this->mount(self::UPLOADER), 'visit' => fn () => $this->visit('/upload')];

        foreach ($open as $tester) {
            $tester()
                ->upload('input[name=photo]', $a)
                ->upload('input[name="photos[]"]', [$b])
                ->click('button[value=store]')
                ->assertState('uploaded', ['a.txt 5 alpha', 'b.txt 4 beta'], in: 'form')
                ->submit()
                ->assertState('uploaded', [], in: 'form');

            // A new pick after the clear goes with the next submit.
            $tester()->upload('input[name=photo]', $a)->click('button[value=store]')->upload('input[name=photo]', $b)->submit()->assertState('uploaded', ['b.txt 4 beta'], in: 'form');

            // A handler that renders the full page too.
            $tester()->upload('input[name=photo]', $a)->click('button[value=refresh]')->assertState('uploaded', ['a.txt 5 alpha'], in: 'form')->submit()->assertState('uploaded', [], in: 'form');
        }
    }

    public function testTheMorphDropsTheFilesOfAnInputThatItReplaces(): void
    {
        [$a, $b] = self::files(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $open = ['mount' => fn () => $this->mount(self::UPLOADER), 'visit' => fn () => $this->visit('/upload')];

        foreach ($open as $tester) {
            // The avatar input gets a new id: as in the browser, the new element has no files.
            // The photo input keeps its element and its files.
            $tester()
                ->upload('#avatar-0', $a)
                ->upload('input[name=photo]', $b)
                ->click('button[value=version]')
                ->assertState('uploaded', ['b.txt 4 beta', 'a.txt 5 alpha'], in: 'form')
                ->submit()
                ->assertState('uploaded', ['b.txt 4 beta'], in: 'form');
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

    /** The cookies that the server got for the page (see CookiesPage.phem), as the page shows them. */
    private static function sent(ComponentTester $page): string
    {
        return trim($page->document()->querySelector('#cookies')->textContent ?? '');
    }

    private function assertThrows(\Closure $action, string $message): void
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }

        self::fail(\sprintf('The action does not throw "%s".', $message));
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
