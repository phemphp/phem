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
 * `phem:announce`: after the morph of an event of the user, the browser announces the new text
 * of the element, also when the element is in a child with no handler.
 */
final class AnnounceTest extends E2eTestCase
{
    public function testTheNewCountOfAChildGoesToTheLiveRegion(): void
    {
        $page = $this->visit('/announce');
        self::assertResponseIsSuccessful();
        expect($page->locator('#count'))->toHaveText('4 results');
        // The page load does not announce.
        expect($page->locator('[data-phem-announcer]'))->toHaveText('');

        $page->getByLabel('Search')->fill('ap');

        expect($page->locator('#count'))->toHaveText('2 results');
        expect($page->getByRole('status'))->toHaveText('2 results');
    }

    public function testTheSameTextAnnouncesNothing(): void
    {
        $page = $this->visit('/announce');
        self::assertResponseIsSuccessful();
        $page->evaluate('() => { window.heard = []; addEventListener("phem:announce", (e) => window.heard.push(e.detail)) }');
        $page->evaluate('() => { window.done = 0; addEventListener("phem:done", () => window.done++) }');

        $page->locator('#star')->click();
        $page->waitForFunction('() => window.done === 1');

        self::assertSame([], $page->evaluate('() => window.heard'));
    }
}
