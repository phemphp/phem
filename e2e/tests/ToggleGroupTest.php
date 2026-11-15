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
 * The `phem-toggle-group` controller: one pressed button, or more than one with `multiple`
 * and `phem-toggle` items, with a roving focus.
 */
final class ToggleGroupTest extends E2eTestCase
{
    public function testAClickPressesOneItemAndTheArrowKeysMoveTheFocus(): void
    {
        $page = $this->visit('/toggle-group');
        self::assertResponseIsSuccessful();

        expect($page->getByRole('group', ['name' => 'Alignment']))->toHaveAttribute('data-orientation', 'horizontal');
        expect($page->getByRole('button', ['name' => 'Left', 'pressed' => true]))->toBeVisible();

        $page->locator('#center')->click();
        expect($page->locator('#center'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#left'))->toHaveAttribute('aria-pressed', 'false');
        expect($page->locator('#align'))->toHaveAttribute('data-phem-toggle-group-value-value', '["center"]');

        // The disabled item is skipped.
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#right'))->toBeFocused();
        $page->keyboard()->press('Space');
        expect($page->locator('#right'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#center'))->toHaveAttribute('aria-pressed', 'false');

        // One item is in the tab order.
        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#right'))->not()->toBeFocused();
        $page->keyboard()->press('Tab');
        expect($page->locator('#right'))->toBeFocused();
        $page->keyboard()->press('Home');
        expect($page->locator('#left'))->toBeFocused();
    }

    public function testAGroupOfToggleButtonsWithMultiple(): void
    {
        $page = $this->visit('/toggle-group');
        self::assertResponseIsSuccessful();

        $page->locator('#bold')->click();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#italic'))->toBeFocused();
        $page->keyboard()->press('Enter');

        expect($page->locator('#bold'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#italic'))->toHaveAttribute('data-pressed', '');
        expect($page->locator('#style'))->toHaveAttribute('data-phem-toggle-group-value-value', '["bold","italic"]');

        $page->locator('#bold')->click();
        expect($page->locator('#bold'))->toHaveAttribute('aria-pressed', 'false');
        expect($page->locator('#italic'))->toHaveAttribute('aria-pressed', 'true');
    }

    public function testTheStateStaysAfterAnEvent(): void
    {
        $page = $this->visit('/toggle-group');
        self::assertResponseIsSuccessful();

        $page->locator('#right')->click();
        $page->locator('#underline')->click();

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        expect($page->locator('#right'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#left'))->toHaveAttribute('aria-pressed', 'false');
        expect($page->locator('#right'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#underline'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#underline'))->toHaveAttribute('data-pressed', '');
    }
}
