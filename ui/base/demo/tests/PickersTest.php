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
        $clear = $page->getByRole('button', ['name' => 'Clear selection']);
        expect($clear)->toBeHidden();

        // A click on the input opens the popup with the 25 fruits.
        $input->click();
        expect($page->getByRole('listbox'))->toBeVisible();
        expect($page->getByRole('option'))->toHaveCount(25);

        $input->fill('berry');
        expect($page->getByRole('option'))->toHaveCount(4);

        // Enter selects the highlighted item: it gets the check, and the Clear button shows.
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($input)->toHaveValue('Strawberry');
        expect($page->locator('[role="option"][data-selected]'))->toHaveCount(1);
        expect($clear)->toBeVisible();

        // The popup opens again with all the fruits, and the check on the selected one.
        $input->click();
        expect($page->getByRole('option'))->toHaveCount(25);
        expect($page->getByRole('option', ['name' => 'Strawberry']))->toHaveAttribute('aria-selected', 'true');

        $input->fill('zzz');
        expect($page->getByRole('option'))->toHaveCount(0);
        expect($page->getByText('No fruits found.'))->toBeVisible();

        // The input has no free text: it gets the label of the selection again.
        $input->blur();
        expect($input)->toHaveValue('Strawberry');

        $clear->click();
        expect($input)->toHaveValue('');
        expect($clear)->toBeHidden();
    }

    public function testAutocompleteFiltersTheTagsInTheBrowser(): void
    {
        $page = $this->visitComponent('autocomplete');

        $input = $page->getByRole('combobox', ['name' => 'Search tags']);

        // The input shows its focus ring, as the input of the Base UI demo.
        $input->focus();
        expect($input)->toHaveCSS('outline-style', 'solid');
        expect($input)->toHaveCSS('outline-width', '2px');

        $input->pressSequentially('too');
        expect($page->getByRole('option', ['name' => 'component: tooltip', 'exact' => true]))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'component: toolbar', 'exact' => true]))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'feature', 'exact' => true]))->toHaveCount(0);

        $page->getByRole('option', ['name' => 'component: toolbar', 'exact' => true])->click();
        expect($input)->toHaveValue('component: toolbar');

        $input->fill('zzz');
        expect($page->getByText('No tags found.'))->toBeVisible();
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

    public function testSelectExamples(): void
    {
        $page = $this->visitComponent('select');

        // The value shows the label of the item, and the label focuses the trigger.
        $formatted = $page->getByRole('region', ['name' => 'Formatting the value']);
        expect($formatted->getByRole('combobox', ['name' => 'Theme']))->toHaveText('System default');
        $labeled = $page->getByRole('region', ['name' => 'Labeling a select']);
        $labeled->getByText('Theme')->click();
        expect($labeled->getByRole('combobox', ['name' => 'Theme']))->toBeFocused();
        expect($page->getByRole('listbox'))->toBeHidden();

        // The placeholder.
        expect($page->getByRole('region', ['name' => 'Placeholder values'])->getByRole('combobox', ['name' => 'Theme']))->toHaveAttribute('data-placeholder', '');

        // Multiple: the template gives the text of two values or more.
        $languages = $page->getByRole('combobox', ['name' => 'Languages']);
        expect($languages)->toHaveText('JavaScript (+1 more)');
        $languages->click();
        $page->getByRole('option', ['name' => 'Python'])->click();
        expect($languages)->toHaveText('JavaScript (+2 more)');
        $page->keyboard()->press('Escape');

        // Object values: the value shows the markup of the item.
        $shipping = $page->getByRole('combobox', ['name' => 'Shipping method']);
        expect($shipping->locator('.text-xs'))->toHaveText('Delivers in 4-6 business days ($4.99)');
        $shipping->click();
        $page->getByRole('option', ['name' => 'Express Delivers in 2-3 business days ($9.99)'])->click();
        expect($shipping->locator('.text-sm')->first())->toHaveText('Express');

        // Grouped: the group labels name their groups.
        $page->getByRole('combobox', ['name' => 'Produce'])->click();
        expect($page->getByRole('group', ['name' => 'Vegetables']))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'Kale']))->toBeVisible();
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
        $hero = $page->getByRole('region', ['name' => 'Demo']);

        $root = $hero->locator('[data-controller~="phem-scroll-area"]');
        expect($root)->toHaveAttribute('data-has-overflow-y', '');
        expect($root)->toHaveAttribute('data-overflow-y-end', '');
        // The viewport is in the tab order only when it scrolls: the controller gives the `tabindex`.
        expect($hero->locator('[data-phem-scroll-area-target="viewport"]'))->toHaveAttribute('tabindex', '0');

        $hero->locator('[data-phem-scroll-area-target="viewport"]')->evaluate('(el) => el.scrollTo(0, el.scrollHeight)');
        expect($root)->toHaveAttribute('data-overflow-y-start', '');
    }

    public function testScrollAreaExamples(): void
    {
        $page = $this->visitComponent('scroll-area');

        // Both scrollbars and a corner.
        $both = $page->getByRole('region', ['name' => 'Both scrollbars'])->locator('[data-controller~="phem-scroll-area"]');
        expect($both)->toHaveAttribute('data-has-overflow-x', '');
        expect($both)->toHaveAttribute('data-has-overflow-y', '');
        expect($both->locator('[data-phem-scroll-area-target="corner"]'))->not()->toHaveAttribute('hidden', '');

        // The fade reads the overflow variables of the viewport.
        $fade = $page->getByRole('region', ['name' => 'Gradient scroll fade'])->locator('[data-phem-scroll-area-target="viewport"]');
        self::assertSame('0px', $fade->evaluate('el => getComputedStyle(el).getPropertyValue("--scroll-area-overflow-y-start").trim()'));
        $fade->evaluate('(el) => el.scrollTo(0, 100)');
        expect($fade)->toHaveCSS('--scroll-area-overflow-y-start', '100px');

        // A tab list in a viewport: it scrolls, and the tabs still work.
        $tabs = $page->getByRole('region', ['name' => 'Combining with Tabs']);
        expect($tabs->locator('[data-controller~="phem-scroll-area"]'))->toHaveAttribute('data-has-overflow-x', '');
        $tabs->getByRole('tab', ['name' => 'Notifications'])->click();
        expect($tabs->getByRole('tabpanel'))->toHaveText('Notifications');
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

    public function testF6MovesTheFocusToTheToastRegion(): void
    {
        $page = $this->visitComponent('toast');

        $region = $page->getByRole('region', ['name' => 'Notifications']);
        $region->evaluate('(el) => el.setAttribute("data-phem-toast-duration-value", "60000")');

        $page->getByRole('button', ['name' => 'Create toast'])->click();
        // Each toast is a dialog that is not modal, as in Base UI.
        expect($region->getByRole('dialog'))->toHaveCount(1);

        $page->keyboard()->press('F6');
        expect($region)->toBeFocused();
    }
}
