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
        expect($page->getByLabel('Verification code'))->toHaveAttribute('id', 'otp-1');
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
