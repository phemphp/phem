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
        expect($page->locator('#trigger'))->toHaveAttribute('data-popup-side', 'bottom');
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

    public function testAnItemUnderAPointerThatDoesNotMoveKeepsTheHighlightOfTheKeyboard(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        // The pointer waits below the trigger, where the next item comes when the popup opens.
        $trigger = $page->locator('#trigger')->boundingBox();
        $page->mouse()->move($trigger['x'] + 20, $trigger['y'] + $trigger['height'] * 1.5);
        $page->locator('#trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#item-honeycrisp'))->toBeFocused();

        $next = $page->locator('#item-honeycrisp + [data-phem-select-target=item]');
        $box = $next->boundingBox();
        self::assertTrue($box['y'] <= $trigger['y'] + $trigger['height'] * 1.5 && $trigger['y'] + $trigger['height'] * 1.5 <= $box['y'] + $box['height'], 'The next item is under the pointer.');
        $page->evaluate('new Promise((resolve) => setTimeout(resolve, 300))');
        expect($page->locator('#item-honeycrisp'))->toBeFocused();

        // A move of the pointer highlights the item under it.
        $page->mouse()->move($trigger['x'] + 30, $trigger['y'] + $trigger['height'] * 1.5);
        expect($next)->toBeFocused();
        expect($next)->toHaveAttribute('data-highlighted', '');
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

    // The text of the selected item is on the text of the value, with no offset of the border of
    // the popup.
    public function testTheAlignedTextIsAtTheSameHeightAsTheValue(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toHaveAttribute('data-side', 'none');

        $offset = $page->evaluate(<<<'JS'
            (() => {
                const value = document.getElementById('value').getBoundingClientRect()
                const text = document.querySelector('#item-honeycrisp [data-phem-select-target=itemText]').getBoundingClientRect()

                return text.top + text.height / 2 - value.top - value.height / 2
            })()
            JS);
        self::assertLessThanOrEqual(0.5, abs($offset), \sprintf('The text of the item is %s px under the value.', $offset));
    }

    // A tall viewport: the popup is over the trigger with all its items.
    public function testTheAlignedPopupShowsAllTheItemsInATallViewport(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $page->setViewportSize(800, 800);

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toHaveAttribute('data-side', 'none');
        expect($page->locator('#trigger'))->toHaveAttribute('data-popup-side', 'bottom');
        self::assertSame(0, (int) $page->evaluate("document.getElementById('list').scrollHeight - document.getElementById('list').clientHeight"));
    }

    // A short viewport: the popup stays over the trigger. It is smaller, and its list scrolls.
    public function testTheAlignedPopupGetsSmallerInAShortViewport(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $trigger = $page->locator('#trigger')->boundingBox();

        // Space under the trigger, but not for all the popup.
        $height = (int) ceil($trigger['y'] + $trigger['height'] + 40);
        $page->setViewportSize(800, $height);

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#popup'))->toHaveAttribute('data-side', 'none');
        expect($page->locator('#item-honeycrisp'))->toBeFocused();

        $popup = $page->locator('#popup')->boundingBox();
        self::assertGreaterThanOrEqual(10 - 1, $popup['y']);
        self::assertLessThanOrEqual($height - 10 + 1, $popup['y'] + $popup['height']);
        self::assertGreaterThan(0, (int) $page->evaluate("document.getElementById('list').scrollHeight - document.getElementById('list').clientHeight"), 'The list scrolls.');

        // The text of the selected item stays on the text of the value.
        $offset = $page->evaluate(<<<'JS'
            (() => {
                const value = document.getElementById('value').getBoundingClientRect()
                const text = document.querySelector('#item-honeycrisp [data-phem-select-target=itemText]').getBoundingClientRect()

                return text.top + text.height / 2 - value.top - value.height / 2
            })()
            JS);
        self::assertLessThanOrEqual(0.5, abs($offset), \sprintf('The text of the item is %s px under the value.', $offset));
    }

    // The trigger is less than 40 px from the bottom of the viewport (the 20 px edge and the two
    // 10 px margins): the popup goes under it.
    public function testThePopupGoesNextToTheTriggerAtTheEdgeOfTheViewport(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $trigger = $page->locator('#trigger')->boundingBox();

        $page->setViewportSize(800, (int) ceil($trigger['y'] + $trigger['height'] + 10));

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#popup'))->not()->toHaveAttribute('data-side', 'none');
    }

    // The trigger is less than 20 px from the top of the viewport: the popup goes next to it.
    public function testThePopupGoesNextToTheTriggerAtTheTopOfTheViewport(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $page->evaluate("document.getElementById('apple').style.marginTop = '5px'");

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#popup'))->not()->toHaveAttribute('data-side', 'none');
    }

    // The popup over the trigger would be less than 100 px high: it goes next to the trigger.
    public function testThePopupGoesNextToTheTriggerWhenItWouldBeTooShort(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $page->evaluate("document.getElementById('apple').style.marginTop = '25px'");
        $trigger = $page->locator('#trigger')->boundingBox();

        // 45 px under the trigger: the trigger is not at the edge, but the popup has about 70 px.
        $page->setViewportSize(800, (int) ceil($trigger['y'] + $trigger['height'] + 45));

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#popup'))->not()->toHaveAttribute('data-side', 'none');
    }

    // In a short viewport, a list that does not scroll puts the popup next to the trigger.
    public function testThePopupGoesNextToTheTriggerWhenItsListDoesNotScroll(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $page->evaluate("document.getElementById('list').style.overflowY = 'visible'");
        $trigger = $page->locator('#trigger')->boundingBox();
        $page->setViewportSize(800, (int) ceil($trigger['y'] + $trigger['height'] + 40));

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toBeVisible();
        expect($page->locator('#popup'))->not()->toHaveAttribute('data-side', 'none');
        self::assertSame('', $page->evaluate("document.getElementById('popup').style.height"));
    }

    // The height of a smaller popup goes away at the close: the next open in a tall viewport shows
    // all the items.
    public function testTheNextOpenRemovesTheHeightOfASmallerPopup(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();
        $trigger = $page->locator('#trigger')->boundingBox();
        $page->setViewportSize(800, (int) ceil($trigger['y'] + $trigger['height'] + 40));

        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toHaveAttribute('data-side', 'none');
        self::assertNotSame('', $page->evaluate("document.getElementById('popup').style.height"));

        $page->keyboard()->press('Escape');
        expect($page->locator('#popup'))->toBeHidden();
        self::assertSame('', $page->evaluate("document.getElementById('popup').style.height"));

        $page->setViewportSize(800, 800);
        $page->locator('#trigger')->click();
        expect($page->locator('#popup'))->toHaveAttribute('data-side', 'none');
        self::assertSame('', $page->evaluate("document.getElementById('popup').style.height"));
        self::assertSame(0, (int) $page->evaluate("document.getElementById('list').scrollHeight - document.getElementById('list').clientHeight"));
    }

    // A move down in a list that scrolls: the item with the focus does not go under the down arrow.
    public function testTheHighlightedItemDoesNotGoUnderTheDownArrow(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#fruits-trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#fruit-apple'))->toBeFocused();

        for ($i = 0; $i < 5; ++$i) {
            $page->keyboard()->press('ArrowDown');
        }

        expect($page->locator('#fruits-down'))->toBeVisible();
        $overlap = $page->evaluate(<<<'JS'
            (() => {
                const arrow = document.getElementById('fruits-down').getBoundingClientRect()
                const item = document.activeElement.getBoundingClientRect()

                return item.bottom - arrow.top
            })()
            JS);
        self::assertLessThanOrEqual(0.5, $overlap, \sprintf('The down arrow is %s px over the item with the focus.', $overlap));
    }

    // A move up in a list that scrolls: the item with the focus does not go under the up arrow.
    public function testTheHighlightedItemDoesNotGoUnderAScrollArrow(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#fruits-trigger')->focus();
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('End');
        expect($page->locator('#fruit-orange'))->toBeFocused();

        for ($i = 0; $i < 6; ++$i) {
            $page->keyboard()->press('ArrowUp');
        }

        expect($page->locator('#fruits-up'))->toBeVisible();
        $overlap = $page->evaluate(<<<'JS'
            (() => {
                const arrow = document.getElementById('fruits-up').getBoundingClientRect()
                const item = document.activeElement.getBoundingClientRect()

                return arrow.bottom - item.top
            })()
            JS);
        self::assertLessThanOrEqual(0.5, $overlap, \sprintf('The up arrow is %s px over the item with the focus.', $overlap));
    }

    // The pointer on a scroll arrow scrolls one item each 40 ms.
    public function testTheScrollArrowScrollsOneItemAtATime(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#fruits-trigger')->click();
        expect($page->locator('#fruits-down'))->toBeVisible();

        $page->evaluate(<<<'JS'
            (() => {
                const list = document.getElementById('fruits-list')
                const samples = window.scrollSamples = [list.scrollTop]
                const start = performance.now()
                const sample = () => {
                    if (list.scrollTop !== samples[samples.length - 1]) samples.push(list.scrollTop)
                    if (performance.now() - start < 1000) requestAnimationFrame(sample)
                }
                requestAnimationFrame(sample)
            })()
            JS);
        $page->locator('#fruits-down')->hover();
        $page->evaluate('new Promise((resolve) => setTimeout(resolve, 1100))');

        $samples = $page->evaluate('window.scrollSamples');
        $item = $page->locator('#fruit-apple')->boundingBox()['height'];
        self::assertGreaterThan(2, \count($samples), 'The list scrolls.');

        // The steps after the first one: one item each.
        $steps = [];
        for ($i = 2; $i < \count($samples) - 1; ++$i) {
            $steps[] = $samples[$i] - $samples[$i - 1];
        }

        foreach ($steps as $step) {
            self::assertEqualsWithDelta($item, $step, 1.5, \sprintf('The steps of the scroll are %s, not one item (%s px).', json_encode($steps), $item));
        }
    }

    public function testAMouseOpenWithNoValueHighlightsTheFirstItem(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#fruits-trigger')->click();
        expect($page->locator('#fruits-popup'))->toBeVisible();
        expect($page->locator('#fruit-apple'))->toBeFocused();
        expect($page->locator('#fruit-apple'))->toHaveAttribute('data-highlighted', '');

        // A focus after a press of the mouse: no focus ring.
        self::assertFalse($page->evaluate("document.getElementById('fruit-apple').matches(':focus-visible')"));
    }

    // A mouse selection gives the focus to the trigger, with no focus ring.
    public function testAMouseSelectionShowsNoFocusRingOnTheTrigger(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        $page->locator('#item-fuji')->click();
        expect($page->locator('#popup'))->toBeHidden();
        expect($page->locator('#trigger'))->toBeFocused();
        self::assertFalse($page->evaluate("document.getElementById('trigger').matches(':focus-visible')"));
    }

    // A press outside closes the popup at the press, and the focus goes to the trigger.
    public function testAPressOutsideClosesThePopupAndFocusesTheTrigger(): void
    {
        $page = $this->visit('/select');
        self::assertResponseIsSuccessful();

        $page->locator('#trigger')->click();
        expect($page->locator('#item-honeycrisp'))->toBeFocused();

        $viewport = $page->viewportSize();
        $mouse = $page->mouse();
        $mouse->move($viewport['width'] - 10, $viewport['height'] - 10);
        $mouse->down();
        expect($page->locator('#popup'))->toBeHidden();
        $mouse->up();

        expect($page->locator('#trigger'))->toBeFocused();
    }
}
