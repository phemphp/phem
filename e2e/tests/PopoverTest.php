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
 * The `phem-popover` and `phem-tooltip` controllers: the position next to the trigger, the flip,
 * the dismiss, and the state through the morph of an event.
 */
final class PopoverTest extends E2eTestCase
{
    public function testPopoverOpensBelowItsTriggerAndStaysOpenAfterAnEvent(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $popup = $page->locator('#popup');
        $page->locator('#trigger')->click();
        expect($popup)->toBeVisible();
        expect($popup)->toHaveAttribute('data-side', 'bottom');
        expect($page->locator('#trigger'))->toHaveAttribute('aria-expanded', 'true');

        $trigger = $page->locator('#trigger')->boundingBox();
        $box = $popup->boundingBox();
        self::assertEqualsWithDelta($trigger['y'] + $trigger['height'], $box['y'], 1);
        self::assertEqualsWithDelta($trigger['x'] + $trigger['width'] / 2, $box['x'] + $box['width'] / 2, 1);

        // A handler in the popup: the morph keeps the popup open, at its place.
        $page->locator('#like')->click();
        expect($page->locator('#likes'))->toHaveExactText('1');
        expect($popup)->toBeVisible();
        expect($popup)->toHaveAttribute('data-side', 'bottom');
        self::assertEqualsWithDelta($box['y'], $popup->boundingBox()['y'], 1);

        // The popover attribute: the popup is in the top layer.
        self::assertTrue($page->evaluate("document.getElementById('popup').matches(':popover-open')"));
    }

    public function testPopoverClosesOnEscapeAndGivesTheFocusBack(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeFocused();
        $page->keyboard()->press('Escape');

        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#trigger'))->toBeFocused();
    }

    public function testPopoverFlipsToTheTopNearTheBottomEdge(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $popup = $page->locator('#low-popup');
        $page->locator('#low-trigger')->click();
        expect($popup)->toHaveAttribute('data-side', 'top');

        $trigger = $page->locator('#low-trigger')->boundingBox();
        $box = $popup->boundingBox();
        self::assertEqualsWithDelta($trigger['y'], $box['y'] + $box['height'], 1);

        // A click outside closes it.
        $page->locator('#likes')->click();
        expect($popup)->toBeHidden();
    }

    public function testTooltipShowsOnHoverAboveItsTrigger(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $tip = $page->locator('#tip');
        expect($page->locator('#archive'))->toHaveAttribute('aria-describedby', 'tip');
        $page->locator('#archive')->hover();
        expect($tip)->toBeVisible();
        expect($tip)->toHaveAttribute('role', 'tooltip');
        expect($tip)->toHaveAttribute('data-side', 'top');

        $trigger = $page->locator('#archive')->boundingBox();
        $box = $tip->boundingBox();
        self::assertEqualsWithDelta($trigger['y'], $box['y'] + $box['height'], 1);

        $page->mouse()->move(5, 5);
        expect($tip)->toBeHidden();
    }
}
