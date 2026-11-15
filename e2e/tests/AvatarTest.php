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
 * The `phem-avatar` controller: the image shows when it loads, else the fallback shows.
 */
final class AvatarTest extends E2eTestCase
{
    public function testAnImageThatLoadsHidesTheFallback(): void
    {
        $page = $this->visit('/avatar');
        self::assertResponseIsSuccessful();

        expect($page->locator('#ada-image'))->toBeVisible();
        expect($page->locator('#ada-image'))->not()->toHaveAttribute('data-loading', '');
        expect($page->locator('#ada-fallback'))->toBeHidden();
    }

    public function testAnImageThatDoesNotLoadShowsTheFallback(): void
    {
        $page = $this->visit('/avatar');
        self::assertResponseIsSuccessful();

        expect($page->locator('#grace-image'))->toHaveAttribute('data-error', '');
        expect($page->locator('#grace-image'))->toBeHidden();
        expect($page->locator('#grace-fallback'))->toBeVisible();
        expect($page->locator('#grace-fallback'))->toHaveText('GH');
    }

    public function testTheStatusStaysAfterAnEventAndANewSrcLoadsAgain(): void
    {
        $page = $this->visit('/avatar');
        self::assertResponseIsSuccessful();

        expect($page->locator('#ada-image'))->toBeVisible();
        $page->evaluate('() => { window.statuses = []; addEventListener("phem-avatar:status", (e) => window.statuses.push(e.detail.status)) }');

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#ada-image'))->toBeVisible();
        expect($page->locator('#ada-fallback'))->toBeHidden();
        expect($page->locator('#grace-fallback'))->toBeVisible();

        // The server render gives a new src that does not load.
        $page->locator('#break')->click();
        expect($page->locator('#ada-image'))->toHaveAttribute('data-error', '');
        expect($page->locator('#ada-image'))->toBeHidden();
        expect($page->locator('#ada-fallback'))->toBeVisible();
        self::assertSame(['loading', 'error'], $page->evaluate('() => window.statuses'));
    }
}
