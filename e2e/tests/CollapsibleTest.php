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
 * The `phem-collapsible` controller: a trigger and a panel with no id.
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
}
