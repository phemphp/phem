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
 * The client and the server together, in a real browser: the events, the morph and the pushes
 * of the Mercure hub.
 */
final class SmokeTest extends E2eTestCase
{
    public function testClickUpdatesTheCountWithNoReload(): void
    {
        $page = $this->visit('/counter');
        self::assertResponseIsSuccessful();
        $page->evaluate('() => { window.loaded = true }');

        $page->locator('#increment')->click();
        expect($page->locator('#count'))->toHaveExactText('1');

        $page->locator('#increment')->click();
        expect($page->locator('#count'))->toHaveExactText('2');

        // The page did not load again: the window keeps its variable, and the last request of
        // the browser is the event, not a Turbo visit of the page.
        self::assertTrue($page->evaluate('() => window.loaded === true'));
        self::assertSame('POST', $this->getLastRequest()?->getMethod());
        self::assertSame('1', $this->getLastRequest()->headers->get('X-Phem-Event'));
    }

    public function testSignInOneBrowserShowsUpInTheOther(): void
    {
        $room = 'room-'.bin2hex(random_bytes(4));
        $alice = $this->visit('/guestbook/'.$room);
        self::assertResponseIsSuccessful();
        $bob = $this->visitInNewBrowser('/guestbook/'.$room);

        // Bob gets the pushes only when his EventSource is open.
        $this->waitForStream($bob);

        $alice->getByLabel('Message')->fill('Hello from Alice');
        $alice->locator('#sign')->click();
        expect($alice->locator('#entries li'))->toHaveExactText('Hello from Alice');

        expect($bob->locator('#entries li'))->toHaveExactText('Hello from Alice');
    }

    public function testMorphKeepsTheNodesOutsideTheChangedComponent(): void
    {
        $page = $this->visit('/counter');
        self::assertResponseIsSuccessful();
        $page->evaluate('() => { document.getElementById("count").marked = true; document.getElementById("increment").marked = true }');

        // The event of <Tally> renders only <Tally>: the count of the page is the same node.
        $page->locator('#hit')->click();
        expect($page->locator('#hits'))->toHaveExactText('1');
        self::assertTrue($page->evaluate('() => document.getElementById("count").marked === true'));

        // The event of the page morphs the page: the button is the same node, with the new count.
        $page->locator('#increment')->click();
        expect($page->locator('#count'))->toHaveExactText('1');
        self::assertTrue($page->evaluate('() => document.getElementById("increment").marked === true'));
        expect($page->locator('#hits'))->toHaveExactText('1');
    }
}
