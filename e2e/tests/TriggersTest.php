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
 * An infinite scroll (`onVisible`) and a status that polls (`onPoll` with `phem:poll`).
 */
final class TriggersTest extends E2eTestCase
{
    public function testVisibleLoadsUntilTheItemsFillTheView(): void
    {
        // 5 items of 100px do not fill the view: the trigger stays in view, and loads again until
        // the items push it out of the view.
        $page = $this->visit('/triggers');
        self::assertResponseIsSuccessful();

        expect($page->locator('#item-10'))->toHaveCount(1);
        $loads = (int) $page->locator('#loads')->textContent();
        self::assertLessThan(7, $loads, 'the loads stop when the trigger leaves the view');

        // The trigger comes into view again: the next items load, until the last one.
        for ($i = 0; $i < 10 && $page->locator('#more')->count() > 0; ++$i) {
            // Before the scroll: the load can end before the next line.
            $loads = (string) $page->locator('#loads')->textContent();
            $page->locator('#more')->scrollIntoViewIfNeeded();
            $page->waitForFunction(\sprintf('() => document.getElementById("loads").textContent !== "%s"', $loads));
        }

        expect($page->locator('#item-40'))->toHaveCount(1);
        expect($page->locator('#more'))->toHaveCount(0);
    }

    public function testPollSendsTheEventAtTheIntervalUntilTheIntervalIsGone(): void
    {
        $page = $this->visit('/triggers');
        self::assertResponseIsSuccessful();

        expect($page->locator('#status'))->toHaveExactText('Polls: 3');
        // The render gives no interval now: no more polls.
        expect($page->locator('#status[data-phem-poll]'))->toHaveCount(0);
        $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 400))');
        expect($page->locator('#status'))->toHaveExactText('Polls: 3');
    }
}
