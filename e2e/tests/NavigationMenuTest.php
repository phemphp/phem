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
 * The `phem-navigation-menu` controller: the content of the open item in one shared popup.
 */
final class NavigationMenuTest extends E2eTestCase
{
    public function testHoverOpensTheContentInThePopupWithItsSize(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->hover();
        expect($page->locator('#overview-content'))->toBeVisible();
        expect($page->locator('#viewport #overview-content'))->toHaveCount(1);
        expect($page->locator('#overview-trigger'))->toHaveAttribute('aria-expanded', 'true');
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', '302px');
        expect($page->locator('#popup'))->toHaveCSS('--popup-height', '122px');
        expect($page->locator('#positioner'))->toHaveAttribute('data-side', 'bottom');

        // The popup is under the trigger, with the side offset.
        $trigger = $page->locator('#overview-trigger')->boundingBox();
        $popup = $page->locator('#popup')->boundingBox();
        self::assertEqualsWithDelta($trigger['y'] + $trigger['height'] + 8, $popup['y'], 1);

        // The trigger names its content with no id on the popup parts.
        self::assertSame('overview-content', $page->locator('#overview-trigger')->getAttribute('aria-controls'));

        $page->locator('#guides-trigger')->hover();
        expect($page->locator('#guides-content'))->toBeVisible();
        expect($page->locator('#guides-content'))->toHaveAttribute('data-activation-direction', 'right');
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', '202px');
        expect($page->locator('#overview-content'))->toBeHidden();
        // The old content goes back to its item.
        expect($page->locator('#overview > #overview-content'))->toHaveCount(1);

        $page->mouse()->move(600, 500);
        expect($page->locator('#positioner'))->toBeHidden();
        expect($page->locator('#guides > #guides-content'))->toHaveCount(1);
    }

    public function testKeyboardOpensTheContentAndTabGoesThroughIt(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->focus();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#guides-trigger'))->toBeFocused();
        $page->keyboard()->press('ArrowLeft');

        // ArrowDown opens the content, and the focus stays on the trigger. Tab goes into it.
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#positioner'))->toBeVisible();
        expect($page->locator('#overview-trigger'))->toBeFocused();
        $page->keyboard()->press('Tab');
        expect($page->locator('#start'))->toBeFocused();
        $page->keyboard()->press('Tab');
        expect($page->locator('#save'))->toBeFocused();

        // Tab after the last element goes to the next trigger, and the menu stays open.
        $page->keyboard()->press('Tab');
        expect($page->locator('#guides-trigger'))->toBeFocused();
        expect($page->locator('#positioner'))->toBeVisible();

        $page->keyboard()->press('Enter');
        expect($page->locator('#guides-trigger'))->toHaveAttribute('aria-expanded', 'true');
        expect($page->locator('#guides-trigger'))->toBeFocused();
        $page->keyboard()->press('Escape');
        expect($page->locator('#positioner'))->toBeHidden();
        expect($page->locator('#guides-trigger'))->toBeFocused();

        expect($page->locator('#blog'))->toHaveAttribute('data-active', '');
    }

    public function testTheFirstOpenPlacesThePositionerAtOnce(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->click();
        expect($page->locator('#positioner'))->toHaveAttribute('data-instant', '');

        $page->locator('#guides-trigger')->click();
        expect($page->locator('#guides-content'))->toBeVisible();
        expect($page->locator('#positioner'))->not()->toHaveAttribute('data-instant', '');
    }

    public function testTheOpenContentStaysAfterAnEvent(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->click();
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        expect($page->locator('#overview-content'))->toBeVisible();
        expect($page->locator('#viewport > #overview-content'))->toHaveCount(1);
        expect($page->locator('[data-phem-navigation-menu-target="content"]'))->toHaveCount(2);
        expect($page->locator('#save'))->toBeFocused();
        expect($page->locator('#nav'))->toHaveAttribute('data-phem-navigation-menu-value-value', 'overview');
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', '302px');

        // A click opened it: the pointer out does not close it.
        $page->mouse()->move(600, 500);
        $page->evaluate('new Promise((resolve) => setTimeout(resolve, 200))');
        expect($page->locator('#overview-content'))->toBeVisible();
    }
}
