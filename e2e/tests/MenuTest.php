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
 * The `phem-menu` controller: the keyboard of a menu, and an item that runs its handler.
 */
final class MenuTest extends E2eTestCase
{
    public function testKeyboardOpensTheMenuMovesTheFocusAndRunsAnItem(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#rename'))->toBeFocused();
        expect($page->locator('#rename'))->toHaveAttribute('data-highlighted', '');

        // The disabled item gets no focus.
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#delete'))->toBeFocused();

        $page->keyboard()->press('Enter');
        expect($page->locator('#log'))->toHaveExactText('delete');
        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#trigger'))->toBeFocused();
    }

    public function testClickOpensTheMenuAndEscapeClosesIt(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#trigger'))->toHaveAttribute('aria-expanded', 'true');

        $page->locator('#rename')->hover();
        expect($page->locator('#rename'))->toBeFocused();
        $page->keyboard()->press('Escape');

        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#trigger'))->toBeFocused();
        self::assertSame('GET', $this->getLastRequest()?->getMethod());
    }
}
