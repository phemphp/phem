<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class PickersTest extends DemoTestCase
{
    public function testComboboxFiltersTheItemsInTheBrowser(): void
    {
        $page = $this->visitComponent('combobox');

        $input = $page->getByRole('combobox', ['name' => 'Choose a fruit']);
        $input->fill('berry');
        expect($page->getByRole('listbox'))->toBeVisible();
        expect($page->getByRole('option'))->toHaveCount(3);

        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($input)->toHaveValue('Strawberry');

        $input->fill('kiwi');
        expect($page->getByRole('option'))->toHaveCount(0);
        expect($page->getByText('No fruits found.'))->toBeVisible();
    }

    public function testAutocompleteAsksTheServerForTheItems(): void
    {
        $page = $this->visitComponent('autocomplete');

        $input = $page->getByRole('combobox', ['name' => 'Search fruits']);
        $input->pressSequentially('ap');
        expect($page->getByRole('option', ['name' => 'Apple', 'exact' => true]))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'Grape', 'exact' => true]))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'Mango', 'exact' => true]))->toHaveCount(0);

        $page->getByRole('option', ['name' => 'Pineapple', 'exact' => true])->click();
        expect($input)->toHaveValue('Pineapple');
    }

    public function testSelect(): void
    {
        $page = $this->visitComponent('select');

        $trigger = $page->getByRole('combobox', ['name' => 'Apple', 'exact' => true]);
        expect($trigger)->toContainText('Select apple');

        $trigger->click();
        expect($page->getByRole('listbox'))->toBeVisible();
        $page->getByRole('option', ['name' => 'Honeycrisp'])->click();
        expect($page->getByRole('listbox'))->toBeHidden();
        expect($trigger)->toContainText('Honeycrisp');
        expect($page->locator('input[name="apple"]'))->toHaveValue('honeycrisp');

        // The keyboard opens it on the selected item, and moves to the next item.
        $trigger->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->getByRole('listbox'))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'Honeycrisp']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($trigger)->toContainText('Granny Smith');
    }

    /**
     * In Base UI, only a move of the pointer highlights an item. A popup that opens under a pointer
     * that does not move keeps the highlight of the keyboard.
     */
    public function testAPopupUnderAStillPointerKeepsTheKeyboardHighlight(): void
    {
        $page = $this->visitComponent('select');
        $trigger = $page->getByRole('combobox', ['name' => 'Apple', 'exact' => true]);
        $trigger->click();
        $page->getByRole('option', ['name' => 'Honeycrisp'])->click();

        // The pointer stays on the place of Honeycrisp. The popup opens over the trigger, with
        // another item under the pointer. The test waits for the pointerover that the browser
        // sends to this item, and then checks the highlight.
        $page->evaluate(<<<'JS'
            () => {
                window.pointerOverItem = false
                document.addEventListener('pointerover', (event) => {
                    if (event.target.closest('[role="option"]')) window.pointerOverItem = true
                }, { capture: true })
            }
            JS);
        $trigger->focus();
        $page->keyboard()->press('ArrowDown');
        $page->waitForFunction('() => window.pointerOverItem');
        expect($page->getByRole('option', ['name' => 'Honeycrisp']))->toHaveAttribute('data-highlighted', '');
    }

    public function testScrollAreaShowsItsScrollbar(): void
    {
        $page = $this->visitComponent('scroll-area');

        $root = $page->locator('[data-controller~="phem-scroll-area"]');
        expect($root)->toHaveAttribute('data-has-overflow-y', '');
        expect($root)->toHaveAttribute('data-overflow-y-end', '');

        $page->locator('[data-phem-scroll-area-target="viewport"]')->evaluate('(el) => el.scrollTo(0, el.scrollHeight)');
        expect($root)->toHaveAttribute('data-overflow-y-start', '');
    }

    public function testToastShowsOneToastForEachClick(): void
    {
        $page = $this->visitComponent('toast');

        // A long duration: the test does not depend on the time of a toast.
        $page->locator('[data-controller~="phem-toast"]')->evaluate('(el) => el.setAttribute("data-phem-toast-duration-value", "60000")');

        $button = $page->getByRole('button', ['name' => 'Create toast']);
        $button->click();
        expect($page->getByText('Toast 1 created'))->toBeVisible();
        expect($page->getByText('This is a toast notification.'))->toBeVisible();

        // The text of the toast behind the new one hides, until the pointer is on the stack.
        $button->click();
        expect($page->getByText('Toast 2 created'))->toBeVisible();
        expect($page->locator('[data-phem-toast-content][data-behind]'))->toHaveCount(1);
        expect($page->locator('[data-phem-toast-content][data-behind]'))->toHaveCSS('opacity', '0');

        $page->getByText('Toast 2 created')->hover();
        expect($page->locator('[data-phem-toast-content][data-behind]'))->toHaveCSS('opacity', '1');
    }
}
