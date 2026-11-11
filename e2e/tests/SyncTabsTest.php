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
 * `data-phem-sync-tabs-value`: after an event, the other tabs of the same page in the browser
 * refresh, with no #[Broadcast] and no Mercure hub. Each tab is a frame of one page (see
 * SyncTabsFrames): the BroadcastChannel is the same for the frames and for the tabs.
 */
final class SyncTabsTest extends E2eTestCase
{
    public function testRefreshesTheOtherTabsOfThePageAfterAnEvent(): void
    {
        $page = $this->visit('/sync-tabs');
        self::assertResponseIsSuccessful();
        $first = $page->frameLocator('#first');
        $second = $page->frameLocator('#second');

        // The user edits a message in the second tab: the refresh keeps it.
        $second->locator('input[name=text]')->fill('Draft');

        $first->locator('input[name=text]')->fill('Hello');
        $first->locator('#sign')->click();
        expect($first->locator('#entries li'))->toHaveExactText('Hello');

        expect($second->locator('#entries li'))->toHaveExactText('Hello');
        expect($second->locator('input[name=text]'))->toHaveValue('Draft');

        // The second tab signs with the draft: the first tab gets it (the newest first).
        $second->locator('#sign')->click();
        expect($first->locator('#entries li'))->toHaveCount(2);
        expect($first->locator('#entries li')->first())->toHaveExactText('Draft');

        // Another room is another page: it sends no refresh.
        $fetches = 'performance.getEntriesByType("resource").filter((e) => e.initiatorType === "fetch").length';
        self::assertSame(0, $page->frame(['name' => 'other'])?->evaluate($fetches));
    }
}
