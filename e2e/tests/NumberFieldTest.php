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

use Playwright\Page\PageInterface;

use function Playwright\Testing\expect;

/**
 * The `phem-number-field` controller: the format of the locale, the keys, the buttons, the wheel
 * and the scrub area.
 */
final class NumberFieldTest extends E2eTestCase
{
    public function testTheUserTypesANumberInTheFormatOfTheLocale(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#amount');
        expect($input)->toHaveValue('10');
        expect($input)->toHaveAttribute('aria-roledescription', 'Number field');
        expect($input)->toHaveAttribute('inputmode', 'numeric');

        $input->fill('1.234,5');
        expect($page->locator('#raw'))->toHaveValue('1234.5');
        $input->press('ArrowUp');
        expect($input)->toHaveValue('1.235,5');
        $input->press('Shift+ArrowDown');
        expect($input)->toHaveValue('1.225,5');

        // On blur, a value out of the limits goes to the limit.
        $input->fill('99999');
        $page->locator('#save')->focus();
        expect($input)->toHaveValue('5.000');
        expect($page->locator('#field'))->toHaveAttribute('data-phem-number-field-value-value', '5000');
        expect($page->locator('#inc'))->toBeDisabled();
    }

    public function testAButtonRepeatsTheStepWhileTheUserHoldsIt(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#amount');
        $input->focus();
        $this->hold($page, '#inc', 1000);

        // One step, then about ten steps in the 600 ms after the first 400 ms.
        $value = (int) $input->inputValue();
        self::assertGreaterThan(15, $value);
        self::assertLessThan(25, $value);
        expect($input)->toBeFocused();

        // A short press is one step.
        $this->hold($page, '#dec', 50);
        expect($input)->toHaveValue((string) ($value - 1));
        expect($input)->toBeFocused();
    }

    public function testTheWheelChangesTheValueWhenTheInputHasTheFocus(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#amount');
        $box = $input->boundingBox();
        $page->mouse()->move($box['x'] + 5, $box['y'] + 5);
        $page->mouse()->wheel(0, -100);
        expect($input)->toHaveValue('10');

        $input->focus();
        $page->mouse()->wheel(0, -100);
        expect($input)->toHaveValue('11');
    }

    public function testAPressInTheScrubAreaLocksThePointer(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $box = $page->locator('#area')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($box['x'] + 5, $box['y'] + 5);
        $mouse->down();
        expect($page->locator('#field'))->toHaveAttribute('data-scrubbing', '');
        expect($page->locator('#area'))->toHaveAttribute('data-scrubbing', '');
        expect($page->locator('#cursor'))->toBeVisible();
        self::assertSame('BODY', $page->evaluate('document.pointerLockElement?.tagName'));
        expect($page->locator('#amount'))->toBeFocused();

        $mouse->up();
        expect($page->locator('#cursor'))->toBeHidden();
        expect($page->locator('#field'))->not()->toHaveAttribute('data-scrubbing', '');
        self::assertNull($page->evaluate('document.pointerLockElement'));
    }

    public function testAPressInTheScrubAreaWithNoMovementFocusesTheInput(): void
    {
        // With no pointer lock, the browser sends the click: the controller sends no second one.
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $page->evaluate('Element.prototype.requestPointerLock = undefined');
        $this->countClicks($page);

        $page->locator('#save')->focus();
        $this->press($page, '#area label');

        expect($page->locator('#amount'))->toBeFocused();
        expect($page->locator('#amount'))->toHaveValue('10');
        $page->waitForFunction('() => window.clicks === 1');
        self::assertSame(1, $page->evaluate('new Promise((resolve) => setTimeout(() => resolve(window.clicks), 100))'));

        // With the pointer lock, the click goes to the body: the controller sends it to the label.
        // The movements of a locked pointer in a headless browser come with the moves back to the
        // center, so the script gives a lock with no movement, and the click of the browser does
        // not go to the label.
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $page->evaluate(<<<'JS'
            window.lockedOn ?? (() => {
                window.lockedOn = null
                let locked = null
                Object.defineProperty(document, 'pointerLockElement', { configurable: true, get: () => locked })
                Element.prototype.requestPointerLock = function () {
                    locked = this
                    window.lockedOn = this.tagName

                    return Promise.resolve()
                }
                document.exitPointerLock = () => (locked = null)
                document.addEventListener('click', (e) => e.isTrusted && e.stopPropagation(), true)
            })()
            JS);
        $this->countClicks($page);

        $page->locator('#save')->focus();
        $this->press($page, '#area label');

        expect($page->locator('#amount'))->toBeFocused();
        expect($page->locator('#amount'))->toHaveValue('10');
        $page->waitForFunction('() => window.clicks === 1');
        self::assertSame(1, $page->evaluate('new Promise((resolve) => setTimeout(() => resolve(window.clicks), 100))'));
        self::assertSame('BODY', $page->evaluate('window.lockedOn'));
        self::assertNull($page->evaluate('document.pointerLockElement'));
    }

    public function testEnterDoesNotFormatTheText(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#amount');
        $input->fill('1234');
        $input->press('Enter');
        expect($input)->toHaveValue('1234');
        expect($page->locator('#raw'))->toHaveValue('1234');

        $page->locator('#save')->focus();
        expect($input)->toHaveValue('1.234');

        // A value out of the limits: the text stays, and the form gets the limit.
        $input->fill('9999');
        $input->press('Enter');
        expect($input)->toHaveValue('9999');
        expect($page->locator('#raw'))->toHaveValue('5000');
    }

    public function testADragInTheScrubAreaChangesTheValue(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        // The movements of a locked pointer in a headless browser come with the moves back to the
        // center. With no pointer lock, the controller reads the position of the pointer.
        $page->evaluate('Element.prototype.requestPointerLock = undefined');

        $box = $page->locator('#area')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($box['x'] + 5, $box['y'] + 5);
        $mouse->down();
        $mouse->move($box['x'] + 45, $box['y'] + 5, ['steps' => 10]);
        $mouse->up();

        // 10 movements of 4 pixels: each one is more than the sensitivity (2 pixels), and changes
        // the value by one step for each pixel, as in Base UI.
        expect($page->locator('#amount'))->toHaveValue('50');
        expect($page->locator('#raw'))->toHaveValue('50');
        expect($page->locator('#cursor'))->toBeHidden();
    }

    public function testTheValueStaysAfterAnEvent(): void
    {
        $page = $this->visit('/number-field');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#amount');
        $input->focus();
        $input->press('Shift+ArrowUp');
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        expect($input)->toHaveValue('20');
        expect($page->locator('#raw'))->toHaveValue('20');
        expect($page->locator('#inc'))->toHaveAttribute('aria-label', 'Increase');
        self::assertSame('amount', $page->evaluate("document.getElementById('inc').getAttribute('aria-controls')"));
    }

    // Counts the clicks on the label of the scrub area in `window.clicks` (the PHP client can run
    // the script two times).
    private function countClicks(PageInterface $page): void
    {
        $page->evaluate("window.clicks ?? (window.clicks = 0, document.querySelector('#area label').addEventListener('click', () => window.clicks++))");
    }

    // A press and a release with no movement.
    private function press(PageInterface $page, string $selector): void
    {
        $box = $page->locator($selector)->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($box['x'] + $box['width'] / 2, $box['y'] + $box['height'] / 2);
        $mouse->down();
        $mouse->up();
    }

    // A press on a button for `ms` milliseconds.
    private function hold(PageInterface $page, string $button, int $ms): void
    {
        $box = $page->locator($button)->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($box['x'] + $box['width'] / 2, $box['y'] + $box['height'] / 2);
        $mouse->down();
        $page->evaluate("new Promise((resolve) => setTimeout(resolve, {$ms}))");
        $mouse->up();
    }
}
