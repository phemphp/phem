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
 * The `phem-radio-group` controller: radios that are not native inputs, with a hidden input.
 */
final class RadioGroupTest extends E2eTestCase
{
    public function testAClickOnALabelChecksTheRadioAndSendsTheValue(): void
    {
        $page = $this->visit('/radio-group');
        self::assertResponseIsSuccessful();

        expect($page->getByRole('radiogroup', ['name' => 'Apple']))->toBeVisible();
        expect($page->getByRole('radio', ['name' => 'Fuji']))->toBeChecked();
        expect($page->locator('#gala .indicator'))->toBeHidden();

        $page->getByText('Gala')->click();
        expect($page->getByRole('radio', ['name' => 'Gala']))->toBeChecked();
        expect($page->getByRole('radio', ['name' => 'Fuji']))->not()->toBeChecked();
        expect($page->locator('#gala .indicator'))->toBeVisible();
        expect($page->locator('#gala'))->toBeFocused();

        // The hidden input sends the value with phem:live.
        expect($page->locator('#apple'))->toHaveText('gala');
        expect($page->locator('#input'))->toHaveValue('gala');
    }

    public function testTheArrowKeysCheckTheNextRadioAndSkipADisabledRadio(): void
    {
        $page = $this->visit('/radio-group');
        self::assertResponseIsSuccessful();

        // The checked radio is the tab stop.
        $page->locator('#save')->focus();
        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#fuji'))->toBeFocused();

        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#gala'))->toBeFocused();
        expect($page->locator('#gala'))->toHaveAttribute('aria-checked', 'true');

        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#pink'))->toBeFocused();
        expect($page->locator('#pink'))->toHaveAttribute('data-checked', '');
        expect($page->locator('#honeycrisp'))->toHaveAttribute('data-disabled', '');

        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#fuji'))->toBeFocused();
        expect($page->locator('#apple'))->toHaveText('fuji');

        // A click on a disabled radio does nothing.
        $page->locator('#label-honeycrisp')->click();
        expect($page->locator('#honeycrisp'))->toHaveAttribute('aria-checked', 'false');
    }

    public function testTheCheckedRadioStaysAfterAnEvent(): void
    {
        $page = $this->visit('/radio-group');
        self::assertResponseIsSuccessful();

        $page->locator('#pink')->click();
        expect($page->locator('#apple'))->toHaveText('pink');

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#pink'))->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('#pink'))->toHaveAttribute('tabindex', '0');
        expect($page->locator('#pink .indicator'))->toBeVisible();
        expect($page->locator('#fuji .indicator'))->toBeHidden();
        expect($page->locator('#input'))->toHaveValue('pink');
    }
}
