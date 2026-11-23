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

namespace App\Tests;

use function Playwright\Testing\expect;

/**
 * The handler of a disabled button (see DisabledPage.phem): the browser sends no click, and the
 * server runs no handler for a click that a user forces, or for a click that waited in the queue.
 */
final class DisabledTest extends E2eTestCase
{
    public function testForcedClickOnADisabledButtonRunsNoHandler(): void
    {
        $page = $this->visit('/disabled');
        self::assertResponseIsSuccessful();

        // A user removes `disabled` in the developer tools, and clicks. The server does not run
        // the handler, and the page does not load again.
        $page->evaluate("() => { document.body.dataset.before = ''; document.querySelector('#publish').disabled = false }");
        $page->locator('#publish')->click();
        $page->waitForFunction("() => !document.documentElement.hasAttribute('aria-busy')");
        $page->locator('#prepare')->click();
        expect($page->locator('#publish'))->toBeEnabled();

        self::assertSame('0', $page->locator('#published')->textContent(), 'the handler did not run');
        self::assertTrue($page->evaluate("() => document.body.hasAttribute('data-before')"), 'no reload');
    }

    public function testQueuedClickOnAButtonThatTheFirstClickDisabledRunsNoHandler(): void
    {
        $page = $this->visit('/disabled');
        self::assertResponseIsSuccessful();

        // Two clicks before the answer of the first one: the second one waits in the queue, and
        // goes after the morph that disables the button.
        $page->evaluate("() => { document.body.dataset.before = ''; const button = document.querySelector('#once'); button.click(); button.click() }");
        expect($page->locator('#sent'))->toHaveExactText('1');
        expect($page->locator('#once'))->toBeDisabled();
        $page->locator('#prepare')->click();
        expect($page->locator('#publish'))->toBeEnabled();

        self::assertSame('1', $page->locator('#sent')->textContent());
        self::assertTrue($page->evaluate("() => document.body.hasAttribute('data-before')"), 'no reload');
    }

    public function testClickRunsTheHandlerWhenTheButtonIsEnabled(): void
    {
        $page = $this->visit('/disabled');
        self::assertResponseIsSuccessful();

        $page->locator('#prepare')->click();
        expect($page->locator('#publish'))->toBeEnabled();

        $page->locator('#publish')->click();
        expect($page->locator('#published'))->toHaveExactText('1');
    }
}
