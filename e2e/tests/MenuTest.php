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
        // The popup opens under the pointer, but the pointer did not move: no item has the highlight.
        expect($page->locator('#context-popup'))->toBeFocused();
        expect($page->locator('#context-copy'))->not()->toHaveAttribute('data-highlighted', '');

        // A move of the pointer highlights the item under it.
        $page->mouse()->move($box['x'] + 55, $box['y'] + 35);
        expect($page->locator('#context-copy'))->toBeFocused();

        $popup = $page->locator('#context-popup')->boundingBox();
        self::assertEqualsWithDelta($box['x'] + 50, $popup['x'], 1);
        self::assertEqualsWithDelta($box['y'] + 30, $popup['y'], 1);

        $page->keyboard()->press('Enter');
        expect($page->locator('#log'))->toHaveExactText('copy');
        expect($page->locator('#context-popup'))->toBeHidden();
    }

    public function testAPopupAndAGroupLabelWithNoIdLinkWithElementReferences(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $controls = "document.getElementById('sort-trigger').ariaControlsElements?.[0]?.classList.contains('sort-popup') ?? false";
        $page->locator('#sort-trigger')->click();
        expect($page->getByRole('group', ['name' => 'Order']))->toBeVisible();
        self::assertTrue($page->evaluate($controls));

        // The morph of the event keeps the parts with no id, and the links.
        $page->locator('#sort-name')->click();
        expect($page->locator('#log'))->toHaveExactText('sort');
        expect($page->getByRole('group', ['name' => 'Order']))->toBeVisible();
        self::assertTrue($page->evaluate($controls));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.sort-popup[id], .sort-label[id]').length"));
    }

    public function testACheckboxItemAndARadioItemKeepTheirStateInTheBrowser(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#sort-trigger')->click();

        // A click checks the item, and the menu stays open.
        $page->locator('#sort-reverse')->click();
        expect($page->locator('#sort-reverse'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#sort-reverse'))->toHaveAttribute('data-checked', '');
        expect($page->locator('.sort-popup'))->toBeVisible();

        // A radio item unchecks the other radio item of its group.
        $page->locator('#sort-grid')->click();
        expect($page->locator('#sort-grid'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#sort-list'))->toHaveAttribute('aria-checked', 'false');
        expect($page->locator('#sort-list'))->toHaveAttribute('data-unchecked', '');

        // The server render has the first state: the state of the browser stays after the morph.
        $page->locator('#sort-name')->click();
        expect($page->locator('#log'))->toHaveExactText('sort');
        expect($page->locator('#sort-reverse'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#sort-grid'))->toHaveAttribute('data-checked', '');
        expect($page->locator('#sort-list'))->toHaveAttribute('aria-checked', 'false');
    }
}
