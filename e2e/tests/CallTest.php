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
 * A handler that JS code calls with a `phem:call` event (`onCall`), with its detail.
 */
final class CallTest extends E2eTestCase
{
    public function testJavaScriptCallsTheHandlerWithTheDetail(): void
    {
        $page = $this->visit('/call');
        self::assertResponseIsSuccessful();

        // As a JS library does: no `bubbles`, on the element of the handler.
        $page->evaluate("document.getElementById('cards').dispatchEvent(new CustomEvent('phem:call', { detail: { card: 'c', index: 0 } }))");
        expect($page->locator('#order'))->toHaveExactText('c,a,b');
        expect($page->locator('#cards li')->first())->toHaveExactText('c');

        // An element in the list calls the handler of the list.
        $page->evaluate("document.getElementById('card-a').dispatchEvent(new CustomEvent('phem:call', { detail: { card: 'a', index: 2 }, bubbles: true }))");
        expect($page->locator('#order'))->toHaveExactText('c,b,a');
    }
}
