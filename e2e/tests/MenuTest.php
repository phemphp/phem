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

use Playwright\Page\PageInterface;

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

    public function testThePointerGoesToASubmenuOnADiagonalPath(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        $page->locator('#move')->hover();
        expect($page->locator('#move-popup'))->toBeVisible();

        // From the lower edge of the trigger to the last item of the submenu, in small steps: the
        // pointer leaves the trigger over the popup of the menu, and the submenu stays open.
        $from = $page->locator('#move')->boundingBox();
        $to = $page->locator('#archive')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($from['x'] + $from['width'] / 2, $from['y'] + $from['height'] - 1);
        $mouse->move($to['x'] + $to['width'] / 2, $to['y'] + $to['height'] / 2, ['steps' => 20]);

        expect($page->locator('#move-popup'))->toBeVisible();
        expect($page->locator('#archive'))->toBeFocused();
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

    // The menu opens at the press, and a release on an item after 200 ms runs it.
    public function testPressDragAndReleaseAfter200MsRunsAnItem(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $trigger = $page->locator('#trigger')->boundingBox();
        $mouse = $page->mouse();

        $mouse->move($trigger['x'] + $trigger['width'] / 2, $trigger['y'] + $trigger['height'] / 2);
        $mouse->down();
        expect($page->locator('#popup'))->toBeVisible();

        // A release counts 200 ms after the press.
        usleep(300_000);

        $delete = $page->locator('#delete')->boundingBox();

        $mouse->move($delete['x'] + $delete['width'] / 2, $delete['y'] + $delete['height'] / 2, ['steps' => 10]);
        $mouse->up();

        expect($page->locator('#log'))->toHaveExactText('delete');
        expect($page->locator('#popup'))->toBeHidden();
    }

    // A press of the right button outside closes the menu at the press, and the
    // focus goes to the trigger.
    public function testARightButtonPressOutsideClosesTheMenuAndFocusesTheTrigger(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#rename'))->toBeFocused();

        $viewport = $page->viewportSize();
        $mouse = $page->mouse();
        $mouse->move($viewport['width'] - 10, $viewport['height'] - 10);
        $mouse->down(['button' => 'right']);
        expect($page->locator('#popup'))->toBeHidden();
        $mouse->up(['button' => 'right']);

        expect($page->locator('#trigger'))->toBeFocused();
    }

    // UShift+Tab in an open menu gives the focus to the trigger.
    public function testShiftTabGivesTheFocusToTheTrigger(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#rename'))->toBeFocused();

        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#trigger'))->toBeFocused();
    }

    // Tab in an open context menu closes it: the menu does not trap the focus.
    public function testTabClosesTheContextMenu(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $box = $page->locator('#area')->boundingBox();
        $page->mouse()->click($box['x'] + 50, $box['y'] + 30, ['button' => 'right']);
        expect($page->locator('#context-popup'))->toBeFocused();

        $page->keyboard()->press('Tab');
        expect($page->locator('#context-popup'))->toBeHidden();
        self::assertFalse($page->evaluate("document.getElementById('context-popup').contains(document.activeElement)"));
    }

    // On macOS, a release of the right button on an item of a context menu, away from
    // the open point, runs the item at once.
    public function testARightButtonReleaseOnAContextMenuItemRunsIt(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();
        $this->releaseOnCopy($page, 'MacIntel');

        expect($page->locator('#log'))->toHaveExactText('copy');
        expect($page->locator('#context-popup'))->toBeHidden();
    }

    // The other systems send `contextmenu` after the release: a release does not run
    // the item, as in Base UI.
    public function testARightButtonReleaseOnAContextMenuItemDoesNothingOnOtherSystems(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();
        $this->releaseOnCopy($page, 'Linux x86_64');

        expect($page->locator('#context-popup'))->toBeVisible();
        self::assertNotSame('copy', $page->locator('#log')->textContent());
    }

    // A submenu that the pointer entered stays open in empty space, and its trigger
    // loses the highlight. The pointer on a sibling item closes it.
    public function testASubmenuStaysOpenWhenThePointerGoesToEmptySpace(): void
    {
        $page = $this->visit('/menu');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        $page->locator('#move')->hover();
        expect($page->locator('#move-popup'))->toBeVisible();

        $page->locator('#inbox')->hover();
        expect($page->locator('#inbox'))->toBeFocused();
        expect($page->locator('#move'))->not()->toHaveAttribute('data-highlighted', '');

        // From the submenu to the empty space at its right.
        $popup = $page->locator('#move-popup')->boundingBox();
        $page->mouse()->move($popup['x'] + $popup['width'] + 150, $popup['y'] + $popup['height'] / 2, ['steps' => 10]);
        usleep(400_000);
        expect($page->locator('#move-popup'))->toBeVisible();

        $page->locator('#delete')->hover();
        expect($page->locator('#move-popup'))->toBeHidden();
    }

    // A press of the right button on the area, a move to "Copy" and a release, with the
    // system `platform` in `navigator.platform`.
    private function releaseOnCopy(PageInterface $page, string $platform): void
    {
        $page->evaluate(\sprintf("Object.defineProperty(navigator, 'platform', { get: () => '%s', configurable: true })", $platform));
        $box = $page->locator('#area')->boundingBox();
        $mouse = $page->mouse();

        $mouse->move($box['x'] + 50, $box['y'] + 30);
        $mouse->down(['button' => 'right']);
        expect($page->locator('#context-popup'))->toBeVisible();

        $copy = $page->locator('#context-copy')->boundingBox();
        $mouse->move($copy['x'] + $copy['width'] / 2, $copy['y'] + $copy['height'] / 2, ['steps' => 5]);
        $mouse->up(['button' => 'right']);
    }
}
