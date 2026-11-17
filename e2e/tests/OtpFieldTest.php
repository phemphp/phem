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
 * The `phem-otp-field` controller: one input for each character of a code.
 */
final class OtpFieldTest extends E2eTestCase
{
    public function testTypingMovesToTheNextSlotAndTheKeysMove(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        expect($page->locator('#otp-1'))->toHaveAttribute('autocomplete', 'one-time-code');
        expect($page->getByRole('textbox', ['name' => 'Verification code']))->toHaveAttribute('id', 'otp-1');
        expect($page->getByLabel('Character 2 of 6'))->toHaveAttribute('id', 'otp-2');

        $page->locator('#otp-1')->click();
        $page->keyboard()->type('12a3');
        expect($page->locator('#otp-3'))->toHaveValue('3');
        expect($page->locator('#otp-4'))->toBeFocused();
        expect($page->locator('#code'))->toHaveValue('123');

        $page->keyboard()->press('Backspace');
        expect($page->locator('#otp-3'))->toHaveValue('');
        expect($page->locator('#otp-3'))->toBeFocused();

        $page->keyboard()->press('Home');
        expect($page->locator('#otp-1'))->toBeFocused();
        $page->keyboard()->press('ArrowRight');
        expect($page->locator('#otp-2'))->toBeFocused();
        $page->keyboard()->press('End');
        expect($page->locator('#otp-3'))->toBeFocused();

        // A click on a slot after the first empty slot focuses the first empty slot.
        $page->locator('#otp-6')->click();
        expect($page->locator('#otp-3'))->toBeFocused();
    }

    public function testAPasteFillsTheSlotsAndSubmitsTheForm(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        $page->locator('#otp-1')->click();
        // A paste event with the text (the PHP client can run the script two times).
        $page->evaluate("window.pasted || (() => { window.pasted = true; const data = new DataTransfer(); data.setData('text/plain', '123 456'); document.activeElement.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true })) })()");

        expect($page->locator('#otp'))->toHaveAttribute('data-complete', '');
        expect($page->locator('#otp-6'))->toHaveValue('6');
        expect($page->locator('#verified'))->toHaveText('123456');
    }

    public function testTheRootIsAGroupWithTheLabel(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        expect($page->locator('#otp'))->toHaveAttribute('role', 'group');
        expect($page->locator('#otp'))->toHaveAttribute('aria-labelledby', 'otp-label');
        expect($page->getByRole('group', ['name' => 'Verification code']))->toHaveAttribute('id', 'otp');
        expect($page->locator('#otp-1'))->toHaveAttribute('type', 'text');
        expect($page->locator('#otp-1'))->toHaveAttribute('maxlength', '6');
        expect($page->locator('#otp-1'))->toHaveAttribute('enterkeyhint', 'next');
        expect($page->locator('#otp-1'))->toHaveAttribute('autocorrect', 'off');
        expect($page->locator('#otp-2'))->not()->toHaveAttribute('maxlength', '6');
        expect($page->locator('#otp-6'))->toHaveAttribute('enterkeyhint', 'done');
    }

    public function testAPasteOfTheSameValueSubmitsAgain(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        // The submits of the form, in `window.submits` (the PHP client can run the script two times).
        $page->evaluate("window.submits ?? (window.submits = 0, document.getElementById('form').addEventListener('submit', () => window.submits++))");

        $page->locator('#otp-1')->click();
        $page->evaluate("window.pasted || (() => { window.pasted = true; const data = new DataTransfer(); data.setData('text/plain', '123456'); document.activeElement.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true })) })()");
        expect($page->locator('#verified'))->toHaveText('123456');
        $page->waitForFunction('() => window.submits === 1');

        // The same code again, from the first slot: the form submits again.
        $page->locator('#otp-1')->click();
        expect($page->locator('#otp-1'))->toBeFocused();
        $page->evaluate("window.pastedAgain || (() => { window.pastedAgain = true; const data = new DataTransfer(); data.setData('text/plain', '123456'); document.activeElement.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true })) })()");
        $page->waitForFunction('() => window.submits === 2');
        expect($page->locator('#verified'))->toHaveText('123456');
    }

    public function testTwoCharactersInOneSlotFillTwoSlots(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        $page->locator('#otp-1')->click();
        $page->keyboard()->type('1');
        expect($page->locator('#otp-2'))->toBeFocused();

        // The caret after the character of the first slot.
        $page->evaluate("(() => { const slot = document.getElementById('otp-1'); slot.focus(); slot.setSelectionRange(1, 1) })()");
        $page->keyboard()->type('2');

        expect($page->locator('#otp-1'))->toHaveValue('1');
        expect($page->locator('#otp-2'))->toHaveValue('2');
        expect($page->locator('#code'))->toHaveValue('12');
    }

    public function testAnImeCompositionCommitsOneTime(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        $page->locator('#otp-1')->click();

        // The events of an IME composition, and the `phem-otp-field:change` events in
        // `window.changes` (the PHP client can run the script two times).
        $page->evaluate(<<<'JS'
            window.changes ?? (() => {
                window.changes = []
                document.getElementById('otp').addEventListener('phem-otp-field:change', (e) => window.changes.push(e.detail.value))
                const slot = document.getElementById('otp-1')
                slot.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }))
                for (const text of ['1', '12']) {
                    slot.value = text
                    slot.dispatchEvent(new InputEvent('input', { bubbles: true, data: text, inputType: 'insertCompositionText', isComposing: true }))
                }
                slot.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: '12' }))
            })()
            JS);

        expect($page->locator('#otp-1'))->toHaveValue('1');
        expect($page->locator('#otp-2'))->toHaveValue('2');
        expect($page->locator('#code'))->toHaveValue('12');
        self::assertSame(['12'], $page->evaluate('window.changes'));
    }

    public function testTheValueStaysAfterAnEvent(): void
    {
        $page = $this->visit('/otp-field');
        self::assertResponseIsSuccessful();

        $page->locator('#otp-1')->click();
        $page->keyboard()->type('42');
        $page->locator('#count')->click();
        expect($page->locator('#counts'))->toHaveText('1');
        expect($page->locator('#otp-1'))->toHaveValue('4');
        expect($page->locator('#otp-2'))->toHaveValue('2');
        expect($page->locator('#code'))->toHaveValue('42');
        expect($page->locator('#otp'))->toHaveAttribute('data-phem-otp-field-value-value', '42');
        expect($page->locator('#otp-3'))->toHaveAttribute('tabindex', '0');
    }
}
