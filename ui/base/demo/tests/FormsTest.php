<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class FormsTest extends DemoTestCase
{
    public function testFieldTracksItsStateAndValidatesOnSubmit(): void
    {
        $page = $this->visitComponent('field');

        $input = $page->getByRole('textbox', ['name' => 'Name']);
        $error = $page->getByText('Please enter your name');

        // The parts link with the ids of the Field, as in Base UI.
        expect($page->locator('label', ['hasText' => 'Name']))->toHaveAttribute('for', 'name-control');
        expect($input)->toHaveAttribute('id', 'name-control');
        expect($input)->toHaveAttribute('aria-describedby', 'name-description');

        $input->focus();
        expect($input)->toHaveAttribute('data-focused', '');

        // The validation mode is onSubmit, as in Base UI: a blur shows no error.
        $input->blur();
        expect($input)->toHaveAttribute('data-touched', '');
        expect($error)->toBeHidden();

        $input->fill('Colm');
        expect($input)->toHaveAttribute('data-dirty', '');
        expect($input)->toHaveAttribute('data-filled', '');
    }

    public function testFormShowsTheErrorOfTheServerAndFocusesAnInvalidField(): void
    {
        $page = $this->visitComponent('form');

        $input = $page->getByRole('textbox', ['name' => 'Homepage']);
        $submit = $page->getByRole('button', ['name' => 'Submit']);
        expect($input)->toHaveValue('https://example.com');

        // The value of the demo is valid for the browser: the server refuses it after 1 second.
        $submit->click();
        expect($page->getByText('The example domain is not allowed'))->toBeVisible();
        expect($input)->toHaveAttribute('aria-invalid', 'true');
        expect($input)->toHaveAttribute('aria-describedby', 'url-error');
        // The lock of the submit (`aria-disabled`) goes at the end of the request.
        expect($submit)->not()->toHaveAttribute('aria-disabled', 'true');

        // An empty value does not go to the server: the field shows the error of the browser,
        // and the control gets the focus.
        $input->fill('');
        $submit->click();
        expect($input)->toBeFocused();
        expect($input)->toHaveAttribute('data-invalid', '');
        expect($page->getByText('The example domain is not allowed'))->toBeHidden();

        // A value that the server accepts removes the error.
        $input->fill('https://base-ui.com');
        $submit->click();
        expect($input)->not()->toHaveAttribute('aria-invalid', 'true');
        expect($input)->toHaveValue('https://base-ui.com');
    }

    public function testNumberField(): void
    {
        $page = $this->visitComponent('number-field');

        $input = $page->getByRole('textbox', ['name' => 'Amount']);
        expect($input)->toHaveValue('100');

        $page->getByRole('button', ['name' => 'Increase'])->click();
        expect($input)->toHaveValue('101');
        $page->getByRole('button', ['name' => 'Decrease'])->click();
        $page->getByRole('button', ['name' => 'Decrease'])->click();
        expect($input)->toHaveValue('99');

        // The arrow keys step the value, and Shift steps by 10.
        $input->focus();
        $page->keyboard()->press('ArrowUp');
        expect($input)->toHaveValue('100');
        $page->keyboard()->press('Shift+ArrowUp');
        expect($input)->toHaveValue('110');
    }

    public function testOtpFieldMovesToTheNextSlot(): void
    {
        $page = $this->visitComponent('otp-field');

        $first = $page->getByRole('textbox', ['name' => 'Verification code']);
        $first->focus();
        // The default validation type is numeric: a letter does not fill a slot.
        $page->keyboard()->type('1a234');
        $slots = $page->locator('input[data-phem-otp-field-target="input"]');
        expect($slots->nth(4))->toBeFocused();
        expect($slots->nth(3))->toHaveValue('4');

        // Backspace in the empty slot 4 goes back and removes the character of slot 3.
        $page->keyboard()->press('Backspace');
        expect($slots->nth(3))->toBeFocused();
        expect($slots->nth(3))->toHaveValue('');
        expect($page->locator('input[type="hidden"][name="code"]'))->toHaveValue('123');

        $page->keyboard()->type('456');
        expect($page->locator('input[type="hidden"][name="code"]'))->toHaveValue('123456');
        expect($page->locator('[data-controller~="phem-otp-field"]'))->toHaveAttribute('data-complete', '');
    }

    public function testSliderMovesWithTheKeyboard(): void
    {
        $page = $this->visitComponent('slider');

        $thumb = $page->getByRole('slider', ['name' => 'Volume']);
        expect($thumb)->toHaveValue('25');

        $thumb->focus();
        $page->keyboard()->press('ArrowRight');
        expect($thumb)->toHaveValue('26');
        $page->keyboard()->press('End');
        expect($thumb)->toHaveValue('100');
        $page->keyboard()->press('Home');
        expect($thumb)->toHaveValue('0');
    }
}
