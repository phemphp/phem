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
 * The behaviors of the Base UI components that the controllers copy, in a real browser: the
 * focus, the pointer and the layout.
 */
final class ParityTest extends E2eTestCase
{
    public function testF6MovesTheFocusToTheToastRegionAndEscapeClosesAToast(): void
    {
        $page = $this->open();
        expect($page->locator('#toasts'))->toHaveAttribute('role', 'region');
        expect($page->locator('#toasts'))->toHaveAttribute('aria-label', 'Notifications');

        $page->evaluate('() => window.dispatchEvent(new CustomEvent("phem:toast", { detail: { message: "Saved", title: "Done" } }))');
        $toast = $page->locator('#toasts [data-phem-toast]');
        expect($toast)->toHaveAttribute('role', 'dialog');
        expect($toast)->toHaveAttribute('tabindex', '0');
        self::assertSame('0px', $page->evaluate('() => document.querySelector("#toasts [data-phem-toast]").style.getPropertyValue("--toast-swipe-movement-x")'));

        $page->locator('#first')->focus();
        $page->keyboard()->press('F6');
        expect($page->locator('#toasts'))->toBeFocused();
        expect($page->locator('#toasts'))->toHaveAttribute('data-expanded', '');

        // Tab goes to the toast, and Escape closes it: the focus goes back.
        $page->keyboard()->press('Tab');
        expect($toast)->toBeFocused();
        $page->keyboard()->press('Escape');
        expect($toast)->toHaveCount(0);
        expect($page->locator('#first'))->toBeFocused();
    }

    public function testEnterAndSpaceClickAButtonThatIsNotAButtonElement(): void
    {
        $page = $this->open();
        expect($page->locator('#div-button'))->toHaveAttribute('role', 'button');

        $page->locator('#div-button')->focus();
        $page->keyboard()->press('Enter');
        expect($page->locator('#log'))->toHaveExactText('save');
        $page->keyboard()->press('Space');
        expect($page->locator('#log'))->toHaveExactText('save, save');

        // A button with aria-disabled keeps the focus, and does nothing.
        $page->locator('#off-button')->focus();
        $page->keyboard()->press('Enter');
        $page->keyboard()->press('Space');
        $box = $page->locator('#off-button')->boundingBox();
        $page->mouse()->click($box['x'] + 5, $box['y'] + 5);
        $page->locator('#div-button')->click();
        expect($page->locator('#log'))->toHaveExactText('save, save, save');
    }

    public function testAModalMenuThatThePointerOpensStaysOpenAndDoesNotLockThePage(): void
    {
        $page = $this->open();
        $page->locator('#hover-trigger')->hover();
        expect($page->locator('#hover-popup'))->toBeVisible();
        self::assertSame('', $page->evaluate('() => document.body.style.pointerEvents'));

        // The pointer goes over the space between the trigger and the popup.
        $page->locator('#hover-one')->hover();
        expect($page->locator('#hover-one'))->toBeFocused();
        expect($page->locator('#hover-popup'))->toBeVisible();

        // Out of an item, and not to another item: the highlight goes.
        $page->locator('#hover-separator')->hover();
        expect($page->locator('#hover-popup'))->toBeFocused();
        expect($page->locator('#hover-one'))->not()->toHaveAttribute('data-highlighted', '');

        // The keys go to an item with aria-disabled. A click on it does nothing.
        $page->locator('#hover-one')->hover();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#hover-off'))->toBeFocused();
        $page->keyboard()->press('Enter');
        expect($page->locator('#hover-popup'))->toBeVisible();
        expect($page->locator('#log'))->toHaveExactText('');

        $page->keyboard()->press('Escape');
        expect($page->locator('#hover-popup'))->toBeHidden();
        expect($page->locator('#hover-popup'))->toHaveAttribute('data-instant', 'dismiss');
    }

    public function testAComboboxShowsTheSelectionOfTheServerRender(): void
    {
        $page = $this->open();
        expect($page->locator('#fruit-input'))->toHaveValue('Olive');
        expect($page->locator('#fruit-value'))->toHaveValue('olive');
        expect($page->locator('#fruit-empty'))->toHaveAttribute('role', 'status');
        expect($page->locator('#fruit-empty'))->toBeEmpty();

        // The popup opens with the highlight on the selected item, in view.
        $page->locator('#fruit-input')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#fruit-Olive'))->toHaveAttribute('data-highlighted', '');
        self::assertGreaterThan(0, $page->evaluate('() => document.getElementById("fruit-list").scrollTop'));
        self::assertTrue($page->evaluate('() => {
            const list = document.getElementById("fruit-list").getBoundingClientRect()
            const item = document.getElementById("fruit-Olive").getBoundingClientRect()

            return item.top >= list.top - 1 && item.bottom <= list.bottom + 1
        }'));

        $page->locator('#fruit-input')->fill('zz');
        expect($page->locator('#fruit-empty'))->toHaveText('No fruit.');
    }

    public function testAPopoverGivesTheFocusToItsFirstFieldAndATouchGivesItToThePopup(): void
    {
        $page = $this->open();
        $page->locator('#share-trigger')->click();
        expect($page->locator('#share-field'))->toBeFocused();

        $page->keyboard()->press('Escape');
        $this->touch($page, 'share-trigger');
        expect($page->locator('#share-popup'))->toBeFocused();
    }

    public function testADialogThatATouchOpensHasTheFocusOnItsPopup(): void
    {
        $page = $this->open();
        $page->locator('#edit-trigger')->click();
        expect($page->locator('#edit-field'))->toBeFocused();
        $page->locator('#edit-close')->click();
        expect($page->locator('#edit-dialog'))->toBeHidden();

        $this->touch($page, 'edit-trigger');
        expect($page->locator('#edit-dialog'))->toBeVisible();
        expect($page->locator('#edit-dialog'))->toBeFocused();
    }

    public function testADrawerHasTheHeightOfTheLastOpenDrawerInIt(): void
    {
        $page = $this->open();
        $page->locator('#drawer-trigger')->click();
        expect($page->locator('#drawer-dialog'))->toBeVisible();
        $height = '() => document.getElementById("drawer-dialog").style.getPropertyValue("--drawer-frontmost-height")';
        self::assertSame($page->evaluate('() => document.getElementById("drawer-dialog").offsetHeight + "px"'), $page->evaluate($height));

        $page->locator('#inner-trigger')->click();
        expect($page->locator('#inner-dialog'))->toBeVisible();
        self::assertSame($page->evaluate('() => document.getElementById("inner-dialog").offsetHeight + "px"'), $page->evaluate($height));

        $page->locator('#inner-close')->click();
        expect($page->locator('#inner-dialog'))->toBeHidden();
        expect($page->locator('#drawer-dialog'))->not()->toHaveAttribute('data-nested-drawer-open', '');
        self::assertSame($page->evaluate('() => document.getElementById("drawer-dialog").offsetHeight + "px"'), $page->evaluate($height));
    }

    public function testTheAccordionAndTheCollapsibleHaveTheStateAttributesOfBaseUi(): void
    {
        $page = $this->open();
        expect($page->locator('#accordion'))->toHaveAttribute('data-orientation', 'vertical');
        expect($page->locator('#shipping-panel'))->toHaveAttribute('data-orientation', 'vertical');
        expect($page->locator('#gifts-header'))->toHaveAttribute('data-disabled', '');
        expect($page->locator('#gifts-panel'))->toHaveAttribute('data-disabled', '');
        expect($page->locator('#shipping-header'))->not()->toHaveAttribute('data-disabled', '');

        // The element of the collapsible has the ending style until the end of the transition.
        $page->locator('#section-trigger')->click();
        expect($page->locator('#section'))->toHaveAttribute('data-ending-style', '');
        expect($page->locator('#section-panel'))->toBeHidden();
        expect($page->locator('#section'))->not()->toHaveAttribute('data-ending-style', '');
    }

    public function testTheSwitchTheScrollAreaAndTheTooltip(): void
    {
        $page = $this->open();

        // Enter toggles a switch, as a button.
        $page->locator('#switch')->focus();
        $page->keyboard()->press('Enter');
        expect($page->locator('#switch'))->toHaveAttribute('aria-checked', 'true');

        // Tab goes only to a viewport that scrolls.
        expect($page->locator('#scrolls-viewport'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#fits-viewport'))->toHaveAttribute('tabindex', '-1');

        // A tooltip is only visual.
        $page->locator('#tip-trigger')->hover();
        expect($page->locator('#tip'))->toBeVisible();
        expect($page->locator('#tip'))->not()->toHaveAttribute('role', 'tooltip');
        self::assertNull($page->evaluate('() => document.getElementById("tip-trigger").getAttribute("aria-describedby")'));
    }

    public function testAMenuOfAMenubarOpensWithNoAnimationOnAnArrowKey(): void
    {
        $page = $this->open();
        $page->locator('#file-trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#file-popup'))->toBeVisible();
        expect($page->locator('#file-popup'))->toHaveAttribute('data-instant', 'group');

        $page->keyboard()->press('Escape');
        expect($page->locator('#file-popup'))->toHaveAttribute('data-instant', 'dismiss');

        $page->locator('#file-trigger')->click();
        expect($page->locator('#file-popup'))->toBeVisible();
        expect($page->locator('#file-popup'))->not()->toHaveAttribute('data-instant', 'group');
    }

    private function open(): PageInterface
    {
        $page = $this->visit('/parity');
        self::assertResponseIsSuccessful();
        expect($page->locator('#div-button'))->toHaveAttribute('role', 'button');

        return $page;
    }

    /**
     * A tap: the press and the click of a touch on the element.
     */
    private function touch(PageInterface $page, string $id): void
    {
        $page->evaluate(\sprintf('() => {
            const el = document.getElementById("%s")
            el.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType: "touch" }))
            el.dispatchEvent(new PointerEvent("click", { bubbles: true, cancelable: true, pointerType: "touch", detail: 1 }))
        }', $id));
    }
}
