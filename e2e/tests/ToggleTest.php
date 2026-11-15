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
 * The `phem-toggle` controller: a toggle button, switches and checkboxes in labels, and a
 * `phem-checkbox-group` of `phem-toggle` checkboxes with a parent.
 */
final class ToggleTest extends E2eTestCase
{
    public function testAClickAndTheKeysToggleAButtonASwitchAndACheckbox(): void
    {
        $page = $this->visit('/toggle');
        self::assertResponseIsSuccessful();

        $page->locator('#bold')->click();
        expect($page->getByRole('button', ['name' => 'Bold', 'pressed' => true]))->toHaveAttribute('data-pressed', '');

        // A click on the text of a label: the browser clicks the hidden input one time.
        $page->locator('#alerts-text')->click();
        expect($page->locator('#alerts'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#alerts-thumb'))->toHaveAttribute('data-checked', '');
        expect($page->locator('#alerts-input'))->toBeChecked();
        expect($page->locator('#alerts'))->toBeFocused();

        // Space on the focused switch.
        $page->keyboard()->press('Space');
        expect($page->locator('#alerts'))->toHaveAttribute('aria-checked', 'false');
        expect($page->locator('#alerts-input'))->not()->toBeChecked();

        // A label for a hidden input: the input gets the value or the unchecked value.
        self::assertSame('no', $page->evaluate("new FormData(document.getElementById('settings')).get('newsletter')"));
        $page->locator('#newsletter-label')->click();
        expect($page->getByRole('switch', ['name' => 'Newsletter', 'checked' => true]))->toBeVisible();
        self::assertSame('yes', $page->evaluate("new FormData(document.getElementById('settings')).get('newsletter')"));

        // The indicator of a checkbox shows when it is checked.
        expect($page->locator('#terms-indicator'))->toBeHidden();
        $page->locator('#terms')->click();
        expect($page->locator('#terms'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#terms-indicator'))->toBeVisible();
        self::assertSame('on', $page->evaluate("new FormData(document.getElementById('settings')).get('terms')"));

        // Enter does not toggle a checkbox.
        $page->keyboard()->press('Enter');
        expect($page->locator('#terms'))->toHaveAttribute('aria-checked', 'true');
    }

    public function testTheParentOfAGroupOfToggleCheckboxes(): void
    {
        $page = $this->visit('/toggle');
        self::assertResponseIsSuccessful();

        expect($page->locator('#all'))->toHaveAttribute('aria-checked', 'mixed');
        expect($page->locator('#pear'))->toHaveAttribute('aria-checked', 'true');

        $page->locator('#all')->click();
        expect($page->locator('#apple'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#plum'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#all'))->toHaveAttribute('aria-checked', 'true');
        self::assertSame(['apple', 'pear', 'plum'], $page->evaluate("new FormData().constructor && [...document.querySelectorAll('#fruits input:checked')].map((i) => i.value)"));

        $page->locator('#all')->click();
        expect($page->locator('#all'))->toHaveAttribute('aria-checked', 'false');

        // The third click gives back the mixed state.
        $page->locator('#all')->click();
        expect($page->locator('#all'))->toHaveAttribute('aria-checked', 'mixed');
        expect($page->locator('#pear'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#apple'))->toHaveAttribute('aria-checked', 'false');
    }

    public function testTheValueOfTheGroupWinsOverTheFirstStateOfAnItem(): void
    {
        $page = $this->visit('/toggle');
        self::assertResponseIsSuccessful();

        // The server render gives `aria-checked="true"` and an input that is not checked.
        expect($page->locator('#pear'))->toHaveAttribute('data-phem-toggle-checked-value', 'true');

        $page->locator('#apple')->click();
        expect($page->locator('#fruits'))->toHaveAttribute('data-phem-checkbox-group-value-value', '["apple","pear"]');
    }

    public function testTheStateStaysAfterAnEvent(): void
    {
        $page = $this->visit('/toggle');
        self::assertResponseIsSuccessful();

        $page->locator('#bold')->click();
        $page->locator('#alerts')->click();
        $page->locator('#terms')->click();
        $page->locator('#plum')->click();

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        // The bound input sent its value to the server.
        expect($page->locator('#alerts-value'))->toHaveText('on');
        expect($page->locator('#alerts'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#bold'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#terms'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#terms-indicator'))->toBeVisible();
        expect($page->locator('#plum'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#pear'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#all'))->toHaveAttribute('aria-checked', 'mixed');
    }
}
