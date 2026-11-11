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

        // The disabled item gets no focus. The trigger of a submenu is an item.
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#move'))->toBeFocused();
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

    public function testPointerOpensASubmenuAndItsItemClosesAllTheMenus(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        $page->locator('#move')->hover();
        expect($page->locator('#move-popup'))->toBeVisible();
        expect($page->locator('#move-popup'))->toHaveAttribute('data-side', 'inline-end');
        expect($page->locator('#move'))->toBeFocused();

        $page->locator('#archive')->click();
        expect($page->locator('#log'))->toHaveExactText('move');
        expect($page->locator('#move-popup'))->toBeHidden();
        expect($page->locator('#popup'))->toBeHidden();
    }

    public function testKeyboardOpensAndClosesASubmenu(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#move'))->toBeFocused();

        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#inbox'))->toBeFocused();

        $page->keyboard()->press('ArrowLeft');
        expect($page->locator('#move-popup'))->toBeHidden();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#move'))->toBeFocused();
    }

    public function testRightClickOpensTheContextMenuAtThePointer(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $box = $page->locator('#area')->boundingBox();
        $page->mouse()->click($box['x'] + 50, $box['y'] + 30, ['button' => 'right']);
        expect($page->locator('#context-popup'))->toBeVisible();
        // The popup is under the pointer: its first item has the highlight of the hover.
        expect($page->locator('#context-copy'))->toBeFocused();

        $popup = $page->locator('#context-popup')->boundingBox();
        self::assertEqualsWithDelta($box['x'] + 50, $popup['x'], 1);
        self::assertEqualsWithDelta($box['y'] + 30, $popup['y'], 1);

        $page->keyboard()->press('Enter');
        expect($page->locator('#log'))->toHaveExactText('copy');
        expect($page->locator('#context-popup'))->toBeHidden();
    }
}
