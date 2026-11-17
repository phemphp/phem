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
 * The `phem-accordion` controller: the state of the header, and the size of a panel with a
 * transition of its height.
 */
final class AccordionTest extends E2eTestCase
{
    public function testTheHeaderHasTheStateOfItsItem(): void
    {
        $page = $this->visit('/accordion');
        self::assertResponseIsSuccessful();

        expect($page->locator('#shipping-header'))->toHaveAttribute('data-closed', '');
        expect($page->locator('#shipping-header'))->not()->toHaveAttribute('data-open', '');

        $page->locator('#shipping-trigger')->click();
        expect($page->locator('#shipping-header'))->toHaveAttribute('data-open', '');
        expect($page->locator('#shipping-header'))->not()->toHaveAttribute('data-closed', '');
        expect($page->locator('#returns-header'))->toHaveAttribute('data-closed', '');
    }

    public function testContentThatGrowsAfterTheOpenIsNotCutAndTheCloseAnimatesFromTheRealHeight(): void
    {
        $page = $this->visit('/accordion');
        self::assertResponseIsSuccessful();

        $page->locator('#shipping-trigger')->click();
        expect($page->locator('#shipping-panel'))->toBeVisible();
        $page->waitForFunction('() => document.getElementById("shipping-panel").getAnimations().length === 0');

        // New content in the open panel: the panel grows with it.
        $page->evaluate('() => document.getElementById("shipping-panel").insertAdjacentHTML("beforeend", "<p>Or one day.</p><p>Or two hours.</p>")');
        $size = $page->evaluate('() => { const panel = document.getElementById("shipping-panel"); return [panel.clientHeight, panel.scrollHeight] }');
        self::assertSame($size[1], $size[0], 'The panel cuts the content that grows after the open.');

        // The close starts from the height of all the content.
        $heights = $page->evaluate(<<<'JS'
            () => {
                const panel = document.getElementById("shipping-panel")
                const real = panel.scrollHeight
                document.getElementById("shipping-trigger").click()

                return [real, parseFloat(getComputedStyle(panel).height)]
            }
            JS);
        self::assertEqualsWithDelta($heights[0], $heights[1], 1, 'The close does not start from the real height of the panel.');
    }

    public function testTheOtherItemClosesFromItsRealHeight(): void
    {
        $page = $this->visit('/accordion');
        self::assertResponseIsSuccessful();

        $page->locator('#shipping-trigger')->click();
        expect($page->locator('#shipping-panel'))->toBeVisible();
        $page->waitForFunction('() => document.getElementById("shipping-panel").getAnimations().length === 0');

        // New content in the open panel.
        $page->evaluate('() => document.getElementById("shipping-panel").insertAdjacentHTML("beforeend", "<p>Or one day.</p><p>Or two hours.</p>")');

        // A click on the other item closes the open item from the height of all its content.
        $heights = $page->evaluate(<<<'JS'
            () => {
                const panel = document.getElementById("shipping-panel")
                const real = panel.scrollHeight
                document.getElementById("returns-trigger").click()

                return [real, parseFloat(getComputedStyle(panel).height)]
            }
            JS);
        self::assertEqualsWithDelta($heights[0], $heights[1], 1, 'The close does not start from the real height of the panel.');
        expect($page->locator('#shipping-panel'))->toHaveAttribute('data-ending-style', '');
        expect($page->locator('#returns-panel'))->toHaveAttribute('data-open', '');
    }
}
