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
 * The `phem-tabs` controller: tabs and panels with values and no ids.
 */
final class TabsTest extends E2eTestCase
{
    public function testAClickAndTheArrowKeysSelectATabByItsValue(): void
    {
        $page = $this->visit('/tabs');
        self::assertResponseIsSuccessful();

        expect($page->getByText('Profile panel'))->toBeVisible();
        expect($page->getByText('Security panel'))->toBeHidden();

        $page->locator('#security')->click();
        expect($page->getByText('Security panel'))->toBeVisible();
        expect($page->locator('#tabs'))->toHaveAttribute('data-phem-tabs-active-value', 'security');
        expect($page->locator('#security'))->toHaveAttribute('data-activation-direction', 'right');

        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('Enter');
        expect($page->getByText('Billing panel'))->toBeVisible();
        expect($page->locator('#billing'))->toHaveAttribute('aria-selected', 'true');
    }

    public function testATabAndItsPanelLinkWithNoId(): void
    {
        $page = $this->visit('/tabs');
        self::assertResponseIsSuccessful();

        // The accessibility tree names the panel with its tab.
        expect($page->getByRole('tabpanel', ['name' => 'Profile']))->toHaveText('Profile panel');
        self::assertSame('Security panel', $page->evaluate("document.getElementById('security').ariaControlsElements[0].textContent"));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.panel[id]').length"));
    }

    public function testTheSelectedTabStaysAfterAnEvent(): void
    {
        $page = $this->visit('/tabs');
        self::assertResponseIsSuccessful();

        $page->locator('#billing')->click();
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->getByText('Billing panel'))->toBeVisible();
        expect($page->getByRole('tabpanel', ['name' => 'Billing']))->toBeVisible();
        self::assertGreaterThan(0, (int) $page->evaluate("parseInt(getComputedStyle(document.getElementById('indicator')).getPropertyValue('--active-tab-left'))"));
    }

    public function testTheTabStopIsTheTabWithTheFocus(): void
    {
        $page = $this->visit('/tabs');
        self::assertResponseIsSuccessful();

        $page->locator('#profile')->click();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#security'))->toBeFocused();
        expect($page->locator('#profile'))->toHaveAttribute('aria-selected', 'true');

        // An arrow key with Shift does not move the focus.
        $page->keyboard()->press('Shift+ArrowRight');
        expect($page->locator('#security'))->toBeFocused();

        // Tab leaves the list, and Shift+Tab comes back to the tab with the focus.
        $page->keyboard()->press('Tab');
        expect($page->locator('#security'))->not()->toBeFocused();
        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#security'))->toBeFocused();

        // The tab stop stays after the morph of an event.
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#security'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#profile'))->toHaveAttribute('tabindex', '-1');
        expect($page->locator('#profile'))->toHaveAttribute('aria-selected', 'true');
    }

    public function testTheIndicatorKeepsTheFractionsOfAPixel(): void
    {
        $page = $this->visit('/tabs');
        self::assertResponseIsSuccessful();

        $page->locator('#security')->click();
        expect($page->locator('#security'))->toHaveAttribute('aria-selected', 'true');

        // The left of the tab in the padding box of the list: the list has a border of 2px and a
        // padding of 0.5px.
        $expected = (float) $page->evaluate("(() => { const list = document.getElementById('list'); return document.getElementById('security').getBoundingClientRect().left - list.getBoundingClientRect().left - list.clientLeft })()");
        $actual = (float) $page->evaluate("parseFloat(document.getElementById('indicator').style.getPropertyValue('--active-tab-left'))");
        self::assertEqualsWithDelta($expected, $actual, 0.01);
    }
}
