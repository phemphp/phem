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
        $hero = $page->getByRole('region', ['name' => 'Demo']);

        $first = $hero->getByRole('textbox', ['name' => 'Verification code']);
        $first->focus();
        // The default validation type is numeric: a letter does not fill a slot.
        $page->keyboard()->type('1a234');
        $slots = $hero->locator('input[data-phem-otp-field-target="input"]');
        expect($slots->nth(4))->toBeFocused();
        expect($slots->nth(3))->toHaveValue('4');

        // Backspace in the empty slot 4 goes back and removes the character of slot 3.
        $page->keyboard()->press('Backspace');
        expect($slots->nth(3))->toBeFocused();
        expect($slots->nth(3))->toHaveValue('');
        expect($hero->locator('input[type="hidden"][name="code"]'))->toHaveValue('123');

        $page->keyboard()->type('456');
        expect($hero->locator('input[type="hidden"][name="code"]'))->toHaveValue('123456');
        expect($hero->locator('[data-controller~="phem-otp-field"]'))->toHaveAttribute('data-complete', '');
    }

    public function testOtpFieldExamples(): void
    {
        $page = $this->visitComponent('otp-field');

        // The label names the first slot, and the other slots get their own label.
        $labeled = $page->getByRole('region', ['name' => 'Labeling an OTP field']);
        expect($labeled->getByRole('textbox', ['name' => 'Verification code']))->toHaveAttribute('id', 'labeled-1');
        expect($labeled->getByRole('textbox', ['name' => 'Character 2 of 6']))->toBeVisible();

        // In a Field, the label names the first slot, and the form gets the code.
        $form = $page->getByRole('region', ['name' => 'Form integration']);
        $form->getByRole('textbox', ['name' => 'Verification code'])->focus();
        $page->keyboard()->type('123456');
        expect($form->locator('input[name="verificationCode"]'))->toHaveValue('123456');

        // Alphanumeric: letters and digits.
        $recovery = $page->getByRole('region', ['name' => 'Alphanumeric verification codes']);
        $recovery->getByRole('textbox', ['name' => 'Recovery code'])->focus();
        $page->keyboard()->type('a7c');
        expect($recovery->getByRole('textbox', ['name' => 'Character 3 of 6']))->toHaveValue('c');

        // The separator of a grouped layout.
        expect($page->getByRole('region', ['name' => 'Grouped layouts'])->getByRole('separator'))->toHaveAttribute('data-orientation', 'horizontal');

        // Custom normalization: the letters become capital letters, and a dash is refused.
        $normalized = $page->getByRole('region', ['name' => 'Custom normalization']);
        $normalized->getByRole('textbox', ['name' => 'Recovery code'])->focus();
        $page->keyboard()->type('a-b');
        expect($normalized->getByRole('textbox', ['name' => 'Recovery code']))->toHaveValue('A');
        expect($normalized->getByRole('textbox', ['name' => 'Character 2 of 6']))->toHaveValue('B');

        // Masked entry: the slots are password inputs.
        expect($page->getByRole('region', ['name' => 'Masked entry'])->locator('input[data-phem-otp-field-target="input"]')->first())->toHaveAttribute('type', 'password');
    }

    public function testSliderMovesWithTheKeyboard(): void
    {
        $page = $this->visitComponent('slider');

        $thumb = $page->getByRole('region', ['name' => 'Demo'])->getByRole('slider', ['name' => 'Volume']);
        expect($thumb)->toHaveValue('25');

        $thumb->focus();
        $page->keyboard()->press('ArrowRight');
        expect($thumb)->toHaveValue('26');
        $page->keyboard()->press('End');
        expect($thumb)->toHaveValue('100');
        $page->keyboard()->press('Home');
        expect($thumb)->toHaveValue('0');
    }

    public function testSliderExamples(): void
    {
        $page = $this->visitComponent('slider');

        // A range: each thumb has its own name and value.
        $range = $page->getByRole('region', ['name' => 'Range slider']);
        expect($range->getByRole('slider', ['name' => 'Minimum value']))->toHaveValue('25');
        expect($range->getByRole('slider', ['name' => 'Maximum value']))->toHaveValue('45');

        // The steps: an arrow key adds the step, Shift with an arrow key the large step.
        $speed = $page->getByRole('region', ['name' => 'Steps'])->getByRole('slider', ['name' => 'Playback speed']);
        $speed->focus();
        $page->keyboard()->press('ArrowRight');
        expect($speed)->toHaveValue('500');
        $page->keyboard()->press('Shift+ArrowRight');
        expect($speed)->toHaveValue('700');

        // The edge alignment keeps the thumb in the control at the max.
        $edge = $page->getByRole('region', ['name' => 'Thumb alignment']);
        $edge->getByRole('slider', ['name' => 'Volume'])->focus();
        $page->keyboard()->press('End');
        $right = $edge->locator('[data-phem-slider-target="thumb"]')->evaluate('el => el.getBoundingClientRect().right');
        $control = $edge->locator('[data-phem-slider-target="control"]')->evaluate('el => el.getBoundingClientRect().right');
        self::assertLessThanOrEqual($control + 0.5, $right);

        // Vertical: ArrowUp raises the value.
        $vertical = $page->getByRole('region', ['name' => 'Vertical'])->getByRole('slider', ['name' => 'Volume']);
        expect($vertical)->toHaveAttribute('aria-orientation', 'vertical');
        $vertical->focus();
        $page->keyboard()->press('ArrowUp');
        expect($vertical)->toHaveValue('36');

        // In a form, the inputs have the name of the slider.
        $form = $page->getByRole('region', ['name' => 'Form integration']);
        expect($form->locator('input[name="volume"]'))->toHaveValue('50');
        expect($form->locator('input[name="price[]"]'))->toHaveCount(2);
    }
}
