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

    public function testATitleAndADescriptionWithNoIdNameThePopupAlsoAfterAnEvent(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $described = "document.getElementById('popup').ariaDescribedByElements?.[0]?.textContent ?? null";
        $page->locator('#trigger')->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Share', 'exact' => true]);
        expect($dialog)->toBeVisible();
        self::assertSame('Send the link to a friend.', $page->evaluate($described));

        // The morph of the event keeps the parts with no id, and the links.
        $page->locator('#like')->click();
        expect($page->locator('#likes'))->toHaveExactText('1');
        expect($dialog)->toBeVisible();
        self::assertSame('Send the link to a friend.', $page->evaluate($described));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.share-title[id], .share-description[id]').length"));
    }

    public function testPopoverClosesOnEscapeAndGivesTheFocusBack(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        // The focus goes to the first element of the popup that Tab goes to.
        $page->locator('#trigger')->click();
        expect($page->locator('#like'))->toBeFocused();
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

    public function testAPreviewCardOpensOnHoverAndOnTheKeyboardFocusOfItsLink(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $page->locator('#card-link')->hover();
        expect($page->locator('#card-popup'))->toBeVisible();
        expect($page->locator('#card-popup'))->toHaveAttribute('aria-hidden', 'true');
        expect($page->locator('#card-link'))->not()->toHaveAttribute('aria-expanded', 'true');
        $page->mouse()->move(0, 0);
        expect($page->locator('#card-popup'))->toBeHidden();

        // The keyboard: the focus opens the card, and Tab skips its content.
        $page->locator('#card-link')->focus();
        $page->keyboard()->press('Shift+Tab');
        $page->keyboard()->press('Tab');
        expect($page->locator('#card-link'))->toBeFocused();
        expect($page->locator('#card-popup'))->toBeVisible();
        $page->keyboard()->press('Tab');
        expect($page->locator('#after-card'))->toBeFocused();
        expect($page->locator('#card-popup'))->toBeHidden();

        // A click on the link opens the link.
        $page->locator('#card-link')->click();
        $page->waitForFunction("location.hash === '#ada'");
        expect($page->locator('#card-popup'))->toBeVisible();
    }

    public function testTooltipShowsOnHoverAboveItsTrigger(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $tip = $page->locator('#tip');
        $page->locator('#archive')->hover();
        expect($tip)->toBeVisible();
        // A tooltip is only visual: no role, and no description of the trigger.
        expect($tip)->not()->toHaveAttribute('role', 'tooltip');
        self::assertNull($page->evaluate("document.getElementById('archive').getAttribute('aria-describedby')"));
        expect($tip)->toHaveAttribute('data-side', 'top');

        $trigger = $page->locator('#archive')->boundingBox();
        $box = $tip->boundingBox();
        self::assertEqualsWithDelta($trigger['y'], $box['y'] + $box['height'], 1);

        $page->mouse()->move(5, 5);
        expect($tip)->toBeHidden();
    }

    public function testATooltipStaysAfterAnEventAndGetsNoIdAndNoDescription(): void
    {
        $page = $this->visit('/popover');
        self::assertResponseIsSuccessful();

        $pin = $page->getByRole('button', ['name' => 'Pin']);
        $pin->hover();
        expect($page->locator('.pin-tip'))->toBeVisible();

        // The morph of the event keeps the open popup, with no id and no description.
        $page->locator('#pin')->dispatchEvent('click');
        expect($page->locator('#likes'))->toHaveText('1');
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.pin-tip[id]').length"));
        self::assertNull($page->evaluate("document.getElementById('pin').getAttribute('aria-describedby')"));
    }
}
