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
 * The `phem-collapsible` controller: a trigger and a panel with no id, and the size of a panel
 * with a transition of its height.
 */
final class CollapsibleTest extends E2eTestCase
{
    public function testATriggerControlsAPanelWithNoIdAlsoAfterAnEvent(): void
    {
        $page = $this->visit('/collapsible');
        self::assertResponseIsSuccessful();

        $controls = "document.getElementById('trigger').ariaControlsElements?.[0]?.textContent ?? null";
        self::assertSame('Details panel', $page->evaluate($controls));

        $page->locator('#trigger')->click();
        expect($page->getByText('Details panel'))->toBeVisible();
        expect($page->locator('#trigger'))->toHaveAttribute('aria-expanded', 'true');

        // The morph keeps the panel with no id, and the trigger points to it again.
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->getByText('Details panel'))->toBeVisible();
        self::assertSame('Details panel', $page->evaluate($controls));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.panel[id]').length"));
    }

    public function testContentThatGrowsAfterTheOpenIsNotCut(): void
    {
        $page = $this->visit('/collapsible');
        self::assertResponseIsSuccessful();

        $page->locator('#grow-trigger')->click();
        expect($page->locator('#grow-panel'))->toBeVisible();
        $page->waitForFunction('() => document.getElementById("grow-panel").getAnimations().length === 0');

        // New content in the open panel: the panel grows with it.
        $page->evaluate('() => document.getElementById("grow-panel").insertAdjacentHTML("beforeend", "<p>Second line</p><p>Third line</p><p>Fourth line</p>")');
        $size = $page->evaluate('() => { const panel = document.getElementById("grow-panel"); return [panel.clientHeight, panel.scrollHeight] }');
        self::assertSame($size[1], $size[0], 'The panel cuts the content that grows after the open.');
    }

    public function testTheCloseAnimatesFromTheRealHeight(): void
    {
        $page = $this->visit('/collapsible');
        self::assertResponseIsSuccessful();

        $page->locator('#grow-trigger')->click();
        expect($page->locator('#grow-panel'))->toBeVisible();
        $page->waitForFunction('() => document.getElementById("grow-panel").getAnimations().length === 0');

        // New content in the open panel.
        $page->evaluate('() => document.getElementById("grow-panel").insertAdjacentHTML("beforeend", "<p>Second line</p><p>Third line</p><p>Fourth line</p>")');

        // The close starts from the height of all the content, and goes to 0.
        $heights = $page->evaluate(<<<'JS'
            () => {
                const panel = document.getElementById("grow-panel")
                const real = panel.scrollHeight
                document.getElementById("grow-trigger").click()

                return [real, parseFloat(getComputedStyle(panel).height)]
            }
            JS);
        self::assertEqualsWithDelta($heights[0], $heights[1], 1, 'The close does not start from the real height of the panel.');
        expect($page->locator('#grow-panel'))->toHaveAttribute('data-ending-style', '');
    }
}
