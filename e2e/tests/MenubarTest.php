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
 * The `phem-menubar` controller: the keyboard and the pointer between the menus of a bar.
 */
final class MenubarTest extends E2eTestCase
{
    public function testArrowKeysMoveBetweenTheTriggersAndTheOpenMenus(): void
    {
        $page = $this->visit('/menubar');
        self::assertResponseIsSuccessful();

        expect($page->getByRole('menubar'))->toHaveCount(1);
        expect($page->getByRole('menuitem', ['name' => 'Edit']))->toHaveAttribute('tabindex', '-1');

        $page->keyboard()->press('Tab');
        expect($page->locator('#file-trigger'))->toBeFocused();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#edit-trigger'))->toBeFocused();
        expect($page->locator('#edit-trigger'))->toHaveAttribute('tabindex', '0');

        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#edit-popup'))->toBeVisible();
        expect($page->locator('#undo'))->toBeFocused();
        expect($page->locator('#bar'))->toHaveAttribute('data-has-submenu-open', '');

        $page->keyboard()->press('ArrowLeft');
        expect($page->locator('#edit-popup'))->toBeHidden();
        expect($page->locator('#file-popup'))->toBeVisible();
        expect($page->locator('#new'))->toBeFocused();

        // ArrowRight on the trigger of a submenu opens the submenu.
        $page->keyboard()->press('End');
        expect($page->locator('#export'))->toBeFocused();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#pdf'))->toBeFocused();

        $page->keyboard()->press('Enter');
        expect($page->locator('#log'))->toHaveExactText('export');
        expect($page->locator('#file-popup'))->toBeHidden();
        expect($page->locator('#file-trigger'))->toBeFocused();
        expect($page->locator('#bar'))->not()->toHaveAttribute('data-has-submenu-open', '');
    }

    public function testHoverOpensAnotherMenuWhileAMenuIsOpen(): void
    {
        $page = $this->visit('/menubar');
        self::assertResponseIsSuccessful();

        $page->locator('#edit-trigger')->hover();
        expect($page->locator('#edit-popup'))->toBeHidden();

        $page->locator('#file-trigger')->click();
        expect($page->locator('#file-popup'))->toBeVisible();
        // The modal menubar locks the page, but its triggers get the pointer.
        self::assertSame('none', $page->evaluate('getComputedStyle(document.body).pointerEvents'));
        self::assertSame('auto', $page->evaluate("getComputedStyle(document.getElementById('help-trigger')).pointerEvents"));

        $page->locator('#help-trigger')->hover();
        expect($page->locator('#help-popup'))->toBeVisible();
        expect($page->locator('#file-popup'))->toBeHidden();

        // The first click on a trigger that the pointer opened keeps its menu open.
        $page->locator('#help-trigger')->click();
        expect($page->locator('#help-popup'))->toBeVisible();

        $page->mouse()->click(400, 300);
        expect($page->locator('#help-popup'))->toBeHidden();
        self::assertSame('auto', $page->evaluate('getComputedStyle(document.body).pointerEvents'));
    }

    public function testTheOpenMenuStaysAfterAnEvent(): void
    {
        $page = $this->visit('/menubar');
        self::assertResponseIsSuccessful();

        $page->locator('#edit-trigger')->click();
        $page->locator('#undo')->click();
        expect($page->locator('#log'))->toHaveExactText('undo');

        expect($page->locator('#edit-popup'))->toBeVisible();
        expect($page->locator('#bar'))->toHaveAttribute('data-has-submenu-open', '');
        expect($page->locator('#bar'))->toHaveAttribute('role', 'menubar');
        expect($page->locator('#edit-trigger'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#edit-trigger'))->toHaveAttribute('data-pressed', '');

        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#help-popup'))->toBeVisible();
        expect($page->locator('#about'))->toBeFocused();
    }
}
