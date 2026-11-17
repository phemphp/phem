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
        expect($page->locator('#popup'))->toHaveCSS('width', '302px');
        expect($page->locator('#popup'))->toHaveCSS('height', '122px');
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
        expect($page->locator('#popup'))->toHaveCSS('width', '202px');
        // The size variables go back to the size of the content after the transition.
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', 'auto');
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

    public function testTheFirstMoveToAnotherTriggerAnimatesThePosition(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->click();
        expect($page->locator('#overview-content'))->toBeVisible();

        // The move starts at the place of the old trigger and goes to the new one.
        $move = $page->evaluate(<<<'JS'
            (() => {
                const positioner = document.getElementById('positioner');
                const from = getComputedStyle(positioner).left;
                document.getElementById('guides-trigger').click();
                const transition = positioner.getAnimations().find((a) => a.transitionProperty === 'left');
                const frames = transition?.effect.getKeyframes() ?? [];

                return { from, start: frames[0]?.left ?? null, to: positioner.style.left };
            })()
        JS);

        self::assertNotNull($move['start'], 'The positioner does not animate its position.');
        self::assertEqualsWithDelta((float) $move['from'], (float) $move['start'], 1);
        self::assertNotEqualsWithDelta((float) $move['start'], (float) $move['to'], 1);
    }

    public function testTheArrowDoesNotSlideAtTheFirstFrame(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        // The popup keeps the size of the last content after a close.
        $page->locator('#overview-trigger')->click();
        expect($page->locator('#overview-content'))->toBeVisible();
        $page->locator('#overview-trigger')->click();
        expect($page->locator('#positioner'))->toBeHidden();

        // The arrow is placed with the size of the new content: it does not move after the first frame.
        $slides = $page->evaluate(<<<'JS'
            new Promise((resolve) => {
                const arrow = document.getElementById('arrow');
                document.getElementById('guides-trigger').click();
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    resolve(arrow.getAnimations().some((a) => a.transitionProperty === 'left'));
                }));
            })
        JS);

        self::assertFalse($slides, 'The arrow slides at the first open.');
    }

    public function testAClickLongAfterAHoverOpenClosesTheMenu(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->hover();
        expect($page->locator('#overview-content'))->toBeVisible();

        $page->evaluate('new Promise((resolve) => setTimeout(resolve, 600))');
        $page->locator('#overview-trigger')->click();
        expect($page->locator('#positioner'))->toBeHidden();
    }

    public function testEscapeOnAnotherTriggerKeepsTheFocus(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->click();
        expect($page->locator('#overview-content'))->toBeVisible();

        $page->locator('#guides-trigger')->focus();
        $page->keyboard()->press('Escape');
        expect($page->locator('#positioner'))->toBeHidden();
        expect($page->locator('#guides-trigger'))->toBeFocused();
    }

    public function testTheMoveToAnotherTriggerAnimatesTheSize(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->click();
        expect($page->locator('#overview-content'))->toBeVisible();

        // The popup goes from the old size to the new one. The positioner has the new size at once.
        $change = $page->evaluate(<<<'JS'
            (() => {
                document.getElementById('guides-trigger').click();
                const popup = document.getElementById('popup');
                const transition = popup.getAnimations().find((a) => a.transitionProperty === 'width');
                const frames = transition?.effect.getKeyframes() ?? [];

                return {
                    from: frames[0]?.width ?? null,
                    to: frames.at(-1)?.width ?? null,
                    positioner: getComputedStyle(document.getElementById('positioner')).width,
                };
            })()
        JS);

        self::assertSame(['from' => '302px', 'to' => '202px', 'positioner' => '202px'], $change);
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', 'auto');
    }

    public function testTheSizeFollowsTheContentAfterTheTransition(): void
    {
        $page = $this->visit('/navigation-menu');
        self::assertResponseIsSuccessful();

        $page->locator('#overview-trigger')->hover();
        expect($page->locator('#overview-content'))->toBeVisible();
        $page->locator('#guides-trigger')->hover();
        expect($page->locator('#guides-content'))->toBeVisible();
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', 'auto');
        expect($page->locator('#popup'))->toHaveCSS('height', '82px');

        // Content that grows in the open popup changes the size of the popup.
        $page->evaluate('document.getElementById("guides-content").style.height = "150px"');
        expect($page->locator('#popup'))->toHaveCSS('height', '152px');
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
        expect($page->locator('#popup'))->toHaveCSS('--popup-width', 'auto');
        expect($page->locator('#popup'))->toHaveCSS('width', '302px');

        // A click opened it: the pointer out does not close it.
        $page->mouse()->move(600, 500);
        $page->evaluate('new Promise((resolve) => setTimeout(resolve, 200))');
        expect($page->locator('#overview-content'))->toBeVisible();
    }
}
