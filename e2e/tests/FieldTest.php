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
 * The `phem-field` and `phem-form` controllers: client validation, server errors and the state
 * after the morph.
 */
final class FieldTest extends E2eTestCase
{
    public function testTheFormStopsAnInvalidSubmitAndFocusesTheField(): void
    {
        $page = $this->visit('/field');
        self::assertResponseIsSuccessful();

        expect($page->locator('#form'))->toHaveAttribute('novalidate', '');
        expect($page->locator('#name-missing'))->toBeHidden();

        $page->locator('#save')->click();
        expect($page->locator('#name-missing'))->toBeVisible();
        expect($page->locator('#name'))->toBeFocused();
        expect($page->locator('#name'))->toHaveAttribute('aria-invalid', 'true');
        expect($page->locator('#name-field'))->toHaveAttribute('data-invalid', '');
        expect($page->locator('#name-label'))->toHaveAttribute('data-invalid', '');
        expect($page->locator('#saves'))->toHaveText('0');

        // The label names the control, and the description and the error describe it.
        expect($page->getByLabel('Name'))->toHaveAttribute('id', 'name');
        self::assertSame('Visible on your profile Enter your name.', $page->evaluate("[...document.getElementById('name').ariaDescribedByElements].map((el) => el.textContent).join(' ')"));

        // After the submit, the field validates on each change.
        $page->locator('#name')->fill('Ada');
        expect($page->locator('#name-missing'))->toBeHidden();
        expect($page->locator('#name-field'))->toHaveAttribute('data-valid', '');
        expect($page->locator('#name-field'))->toHaveAttribute('data-dirty', '');

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
    }

    public function testAServerErrorStaysUntilTheUserChangesTheValue(): void
    {
        $page = $this->visit('/field');
        self::assertResponseIsSuccessful();

        $page->locator('#name')->fill('Shoes');
        $page->locator('#save')->click();
        expect($page->locator('#name-error'))->toHaveText('This name is taken.');
        expect($page->locator('#name-field'))->toHaveAttribute('data-invalid', '');
        self::assertSame('This name is taken. Visible on your profile', $page->evaluate("[...document.getElementById('name').ariaDescribedByElements].map((el) => el.textContent).join(' ')"));
        self::assertSame('This name is taken.', $page->evaluate("document.getElementById('name').validationMessage"));

        // A new submit of the same value does not go to the server.
        $page->locator('#save')->click();
        expect($page->locator('#name'))->toBeFocused();

        $page->locator('#name')->fill('Boots');
        expect($page->locator('#name-error'))->toBeHidden();
        expect($page->locator('#name'))->not()->toHaveAttribute('aria-invalid', 'true');
        expect($page->locator('#name-field'))->toHaveAttribute('data-valid', '');

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#name-error'))->toHaveCount(0);
    }

    public function testTheModesOfValidation(): void
    {
        $page = $this->visit('/field');
        self::assertResponseIsSuccessful();

        // onBlur: the error shows when the control loses the focus.
        $page->locator('#email')->fill('ada');
        expect($page->locator('#email-error'))->toBeHidden();
        $page->keyboard()->press('Tab');
        expect($page->locator('#email-error'))->toBeVisible();
        self::assertNotSame('', $page->evaluate("document.getElementById('email-error').textContent"));
        expect($page->locator('#email-field'))->toHaveAttribute('data-touched', '');

        // onChange: the error shows after the debounce time.
        $page->locator('#code')->fill('12a');
        expect($page->locator('#code-error'))->toBeVisible();
        $page->locator('#code')->fill('1234');
        expect($page->locator('#code-error'))->toBeHidden();

        // A disabled fieldset disables the field.
        expect($page->locator('#city-field'))->toHaveAttribute('data-disabled', '');
    }

    public function testTheStateStaysAfterAnEvent(): void
    {
        $page = $this->visit('/field');
        self::assertResponseIsSuccessful();

        $page->locator('#save')->click();
        expect($page->locator('#name-missing'))->toBeVisible();

        $page->locator('#count')->click();
        expect($page->locator('#counts'))->toHaveText('1');
        expect($page->locator('#name-missing'))->toBeVisible();
        expect($page->locator('#name-missing'))->toHaveAttribute('data-open', '');
        expect($page->locator('#name'))->toHaveAttribute('aria-invalid', 'true');
        expect($page->locator('#form'))->toHaveAttribute('novalidate', '');
    }
}
