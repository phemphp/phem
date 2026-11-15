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
 * The `phem-select` controller: a button that opens a list of options.
 */
final class SelectTest extends E2eTestCase
{
    public function testThePopupPutsTheSelectedItemOverTheTrigger(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        expect($page->locator('#value'))->toHaveText('Honeycrisp');

        $page->locator('#trigger')->click();
        expect($page->getByRole('listbox'))->toBeVisible();
        expect($page->locator('#popup'))->toHaveAttribute('data-side', 'none');
        expect($page->locator('#trigger'))->toHaveAttribute('data-popup-side', 'none');
        expect($page->locator('#item-honeycrisp'))->toBeFocused();
        expect($page->locator('#item-honeycrisp'))->toHaveAttribute('data-highlighted', '');

        // The text of the item is on the text of the value.
        $offset = $page->evaluate(<<<'JS'
            (() => {
                const value = document.getElementById('value').getBoundingClientRect()
                const text = document.querySelector('#item-honeycrisp [data-phem-select-target=itemText]').getBoundingClientRect()

                return [Math.abs(value.top + value.height / 2 - text.top - text.height / 2), Math.abs(value.left - text.left)]
            })()
            JS);
        self::assertLessThanOrEqual(2, $offset[0]);
        self::assertLessThanOrEqual(2, $offset[1]);
    }

    public function testTheKeyboardSelectsAnItem(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#item-honeycrisp'))->toBeFocused();

        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#value'))->toHaveText('Granny Smith');
        expect($page->locator('#input'))->toHaveValue('granny');
        expect($page->locator('#trigger'))->toBeFocused();
        expect($page->locator('#trigger'))->toHaveAttribute('aria-expanded', 'false');

        $page->keyboard()->press('Space');
        $page->keyboard()->type('p');
        expect($page->locator('#item-pink'))->toBeFocused();
        $page->keyboard()->press('Escape');
        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#trigger'))->toBeFocused();
    }

    public function testAReleaseAfterADragFromTheTriggerSelectsTheItem(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $trigger = $page->locator('#trigger')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($trigger['x'] + 20, $trigger['y'] + $trigger['height'] / 2);
        $mouse->down();
        expect($page->locator('#popup'))->toBeVisible();

        $item = $page->locator('#item-gala')->boundingBox();
        $mouse->move($item['x'] + 20, $item['y'] + $item['height'] / 2, ['steps' => 5]);
        $mouse->up();

        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#value'))->toHaveText('Gala');
    }

    public function testAMultipleSelectStaysOpenAndScrollsWithTheArrows(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        expect($page->locator('#fruits-value'))->toHaveText('Select fruits');

        $page->locator('#fruits-trigger')->click();
        expect($page->locator('#fruits-popup'))->toHaveAttribute('data-side', 'bottom');
        expect($page->locator('#fruits-trigger'))->toHaveAttribute('data-pressed', '');
        expect($page->locator('#fruits-down'))->toBeVisible();
        expect($page->locator('#fruits-up'))->toBeHidden();

        $page->locator('#fruit-banana')->click();
        $page->locator('#fruit-apple')->click();
        expect($page->locator('#fruits-popup'))->toBeVisible();
        expect($page->locator('#fruits-value'))->toHaveText('Apple, Banana');
        expect($page->locator('#fruit-banana'))->toHaveAttribute('aria-selected', 'true');

        // The pointer on the down arrow scrolls the list.
        $page->locator('#fruits-down')->hover();
        expect($page->locator('#fruits-up'))->toBeVisible();
        expect($page->locator('#fruits-up'))->toHaveAttribute('data-visible', '');
        $page->mouse()->move(0, 0);
        self::assertGreaterThan(0, (int) $page->evaluate("document.getElementById('fruits-list').scrollTop"));
    }

    public function testTheValueAndTheOpenPopupStayAfterAnEvent(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        $page->locator('#item-fuji')->click();
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#value'))->toHaveText('Fuji');
        expect($page->locator('#input'))->toHaveValue('fuji');

        $page->locator('#fruits-trigger')->click();
        $page->locator('#fruit-cherry')->click();
        $page->locator('#popup-save')->click();
        expect($page->locator('#saves'))->toHaveText('2');
        expect($page->locator('#fruits-popup'))->toBeVisible();
        expect($page->locator('#fruits-value'))->toHaveText('Cherry');
        expect($page->locator('#fruit-cherry'))->toHaveAttribute('aria-selected', 'true');
        expect($page->locator('#fruits-trigger'))->toHaveAttribute('aria-expanded', 'true');
    }
}
