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
 * The `phem-toolbar` controller: one tab stop, the arrow keys, and focusable disabled items.
 */
final class ToolbarTest extends E2eTestCase
{
    public function testTheArrowKeysMoveTheFocusBetweenTheItems(): void
    {
        $page = $this->visit('/toolbar');
        self::assertResponseIsSuccessful();

        expect($page->getByRole('toolbar', ['name' => 'Format']))->toHaveAttribute('aria-orientation', 'horizontal');
        expect($page->getByRole('group', ['name' => 'Alignment']))->toBeVisible();
        expect($page->getByRole('separator'))->toHaveAttribute('aria-orientation', 'vertical');

        // One tab stop: the first item.
        $page->locator('#save')->focus();
        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#left'))->toBeFocused();

        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#center'))->toBeFocused();

        // A disabled item gets the focus.
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#bold'))->toBeFocused();
        expect($page->locator('#bold'))->toHaveAttribute('data-focusable', '');

        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#zoom'))->toBeFocused();

        // The arrow keys move the caret of the input, and leave it at the end of the text.
        $page->keyboard()->press('End');
        $page->keyboard()->press('ArrowLeft');
        expect($page->locator('#zoom'))->toBeFocused();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#zoom'))->toBeFocused();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#left'))->toBeFocused();

        $page->keyboard()->press('ArrowLeft');
        expect($page->locator('#zoom'))->toBeFocused();
        expect($page->locator('#zoom'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#left'))->toHaveAttribute('tabindex', '-1');
    }

    public function testADisabledItemDoesNotSendItsEvent(): void
    {
        $page = $this->visit('/toolbar');
        self::assertResponseIsSuccessful();

        $page->locator('#bold')->click(['force' => true]);
        $page->keyboard()->press('Enter');
        $page->keyboard()->press('Space');
        expect($page->locator('#bold'))->toBeFocused();

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#bolds'))->toHaveText('0');
    }

    public function testAToggleGroupLeavesTheArrowKeysToTheToolbar(): void
    {
        $page = $this->visit('/toolbar');
        self::assertResponseIsSuccessful();

        $page->locator('#align-end')->click();
        expect($page->getByRole('button', ['name' => 'End', 'pressed' => true]))->toBeFocused();

        // The last item of the group goes to the next item of the toolbar, not to the first item of the group.
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#editor-bold'))->toBeFocused();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#align-start'))->toBeFocused();
        $page->keyboard()->press('ArrowLeft');
        expect($page->locator('#editor-bold'))->toBeFocused();

        // One tab stop for the toolbar: the item of the last focus.
        expect($page->locator('#editor [tabindex="0"]'))->toHaveCount(1);
        expect($page->locator('#editor-bold'))->toHaveAttribute('tabindex', '0');

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#editor [tabindex="0"]'))->toHaveCount(1);
        expect($page->locator('#editor-bold'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#align-end'))->toHaveAttribute('aria-pressed', 'true');
    }

    public function testTheTabStopStaysAfterAnEvent(): void
    {
        $page = $this->visit('/toolbar');
        self::assertResponseIsSuccessful();

        $page->locator('#help')->focus();
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        expect($page->locator('#help'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#left'))->toHaveAttribute('tabindex', '-1');
        expect($page->locator('#bold'))->toHaveAttribute('aria-disabled', 'true');
        expect($page->locator('#bold'))->toHaveAttribute('data-disabled', '');
        expect($page->locator('#toolbar'))->toHaveAttribute('role', 'toolbar');
    }
}
