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
 * The `phem-tooltip` controller: one tooltip at a time in a group, and the close at the press.
 */
final class TooltipTest extends E2eTestCase
{
    public function testTheNextTooltipOfAGroupHidesTheLastOneAtOnce(): void
    {
        $page = $this->visit('/tooltip');
        self::assertResponseIsSuccessful();

        $page->locator('#bold')->hover();
        expect($page->locator('#bold-tip'))->toBeVisible();
        expect($page->locator('#bold-tip'))->not()->toHaveAttribute('data-starting-style', '');

        // The buttons touch: the `pointerout` of "Bold" goes to "Italic".
        $italic = $page->locator('#italic')->boundingBox();
        $page->mouse()->move($italic['x'] + $italic['width'] / 2, $italic['y'] + $italic['height'] / 2);

        // At once, with no retry: the close animation of "Bold" (400 ms) does not run.
        self::assertSame([true, 'delay', false], $page->evaluate("[
            document.getElementById('bold-tip').hidden,
            document.getElementById('bold-tip').getAttribute('data-instant'),
            document.getElementById('italic-tip').hidden,
        ]"));
    }

    public function testAPressClosesTheTooltipBeforeTheClick(): void
    {
        $page = $this->visit('/tooltip');
        self::assertResponseIsSuccessful();

        $tip = $page->locator('#bold-tip');
        $page->locator('#bold')->hover();
        expect($tip)->toBeVisible();

        $page->mouse()->down();
        expect($tip)->toBeHidden();
        expect($tip)->toHaveAttribute('data-instant', 'dismiss');
        expect($page->locator('#presses'))->toHaveExactText('0');

        $page->mouse()->up();
        expect($page->locator('#presses'))->toHaveExactText('1');
        expect($tip)->toBeHidden();
    }

    public function testEnterClosesTheTooltipAtTheClick(): void
    {
        $page = $this->visit('/tooltip');
        self::assertResponseIsSuccessful();

        // The keyboard focus: Shift+Tab from "Italic" to "Bold".
        $tip = $page->locator('#bold-tip');
        $page->locator('#italic')->focus();
        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#bold'))->toBeFocused();
        expect($tip)->toBeVisible();

        $page->keyboard()->press('Enter');
        expect($tip)->toBeHidden();
        expect($page->locator('#presses'))->toHaveExactText('1');
    }
}
