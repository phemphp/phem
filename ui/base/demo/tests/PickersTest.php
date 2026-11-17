<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class PickersTest extends DemoTestCase
{
    public function testComboboxFiltersTheItemsInTheBrowser(): void
    {
        $page = $this->visitComponent('combobox');
        $hero = $page->getByRole('region', ['name' => 'Demo']);

        $input = $hero->getByRole('combobox', ['name' => 'Choose a fruit']);
        $clear = $hero->getByRole('button', ['name' => 'Clear selection']);
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
        expect($hero->locator('[role="option"][data-selected]'))->toHaveCount(1);
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

    public function testComboboxExamples(): void
    {
        $page = $this->visitComponent('combobox');

        // IDs: the input shows the label of the default value, and the form gets the ID.
        $ids = $page->getByRole('region', ['name' => 'Value selection with IDs']);
        $fruit = $ids->getByRole('combobox', ['name' => 'Choose a fruit']);
        expect($fruit)->toHaveValue('Banana');
        expect($ids->locator('input[name="fruit"]'))->toHaveValue('banana');
        $fruit->fill('man');
        $page->getByRole('option', ['name' => 'Mango'])->click();
        expect($fruit)->toHaveValue('Mango');
        expect($ids->locator('input[name="fruit"]'))->toHaveValue('mango');

        // Multiple: a click on an item adds a chip, and the popup stays open.
        $multiple = $page->getByRole('region', ['name' => 'Multiple select']);
        $languages = $multiple->getByRole('combobox', ['name' => 'Programming languages']);
        $languages->click();
        $page->getByRole('option', ['name' => 'TypeScript'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove TypeScript']))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'TypeScript']))->toHaveAttribute('aria-selected', 'true');
        $page->getByRole('option', ['name' => 'Go'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove Go']))->toBeVisible();

        // Backspace in the empty input removes the last chip.
        $languages->focus();
        $page->keyboard()->press('Backspace');
        expect($multiple->getByRole('button', ['name' => 'Remove Go']))->toHaveCount(0);
        expect($multiple->getByRole('button', ['name' => 'Remove TypeScript']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // The input in the popup: the trigger opens it with the focus in the input, and a choice
        // shows the label on the trigger.
        $inside = $page->getByRole('region', ['name' => 'Input inside popup']);
        $country = $inside->getByRole('button', ['name' => 'Country']);
        expect($country)->toContainText('Select country');
        $country->click();
        $search = $inside->getByRole('combobox', ['name' => 'Country']);
        expect($search)->toBeFocused();
        $search->fill('nor');
        $page->getByRole('option', ['name' => 'Norway'])->click();
        expect($country)->toContainText('Norway');
        expect($country)->toBeFocused();
        $page->keyboard()->press('Escape');

        // Async search: the server gives the results of the query.
        $async = $page->getByRole('region', ['name' => 'Async search (single)']);
        $reviewer = $async->getByRole('combobox', ['name' => 'Assign reviewer']);
        $reviewer->click();
        $page->keyboard()->press('ArrowDown');
        expect($async->locator('text=Start typing to search people…'))->toBeVisible();
        $reviewer->fill('designer');
        $lindsay = $async->getByRole('option', ['name' => 'Lindsay Walton']);
        $lindsay->click();
        expect($reviewer)->toHaveValue('Lindsay Walton');
        $reviewer->fill('zzz');
        expect($async->locator('text=No matches for "zzz".'))->toBeVisible();
        $page->keyboard()->press('Escape');

        // Async search with chips.
        $multiple = $page->getByRole('region', ['name' => 'Async search (multiple)']);
        $reviewers = $multiple->getByRole('combobox', ['name' => 'Assign reviewers']);
        $reviewers->fill('lindsay');
        $multiple->getByRole('option', ['name' => 'Lindsay Walton'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove Lindsay Walton']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // Creatable: a new text shows a Create item, which opens a dialog with the text.
        $creatable = $page->getByRole('region', ['name' => 'Creatable']);
        $labels = $creatable->getByRole('combobox', ['name' => 'Labels']);
        $labels->fill('urgent');
        $creatable->getByRole('option', ['name' => 'Create "urgent"'])->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Create new label']);
        expect($dialog)->toBeVisible();
        expect($dialog->getByRole('textbox', ['name' => 'Label name']))->toHaveValue('urgent');
        $dialog->getByRole('button', ['name' => 'Create'])->click();
        expect($dialog)->toBeHidden();
        expect($creatable->getByRole('button', ['name' => 'Remove urgent']))->toBeVisible();

        // Virtualized: the server renders the first 50 matches, and the status gives the total.
        $virtual = $page->getByRole('region', ['name' => 'Virtualized']);
        $items = $virtual->getByRole('combobox', ['name' => 'Search 10,000 items']);
        $items->fill('1');
        expect($virtual->locator('[data-phem-combobox-target="item"]'))->toHaveCount(50);
        expect($virtual->locator('text=Showing 50 of'))->toBeVisible();
        $items->fill('Item 0042');
        expect($virtual->getByRole('option', ['name' => 'Item 0042']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // Grouped: the filter hides a group with no match.
        $produce = $page->getByRole('region', ['name' => 'Grouped'])->getByRole('combobox', ['name' => 'Select produce']);
        $produce->fill('ka');
        expect($page->getByRole('group', ['name' => 'Vegetables']))->toBeVisible();
        expect($page->getByRole('group', ['name' => 'Fruits']))->toBeHidden();
        expect($page->getByRole('option', ['name' => 'Kale']))->toBeVisible();
    }

    public function testAutocompleteFiltersTheTagsInTheBrowser(): void
    {
        $page = $this->visitComponent('autocomplete');

        $hero = $page->getByRole('region', ['name' => 'Demo']);
        $input = $hero->getByRole('combobox', ['name' => 'Search tags']);

        // The input shows its focus ring, as the input of the Base UI demo.
        $input->focus();
        expect($input)->toHaveCSS('outline-style', 'solid');
        expect($input)->toHaveCSS('outline-width', '2px');

        $input->pressSequentially('too');
        expect($hero->getByRole('option', ['name' => 'component: tooltip', 'exact' => true]))->toBeVisible();
        expect($hero->getByRole('option', ['name' => 'component: toolbar', 'exact' => true]))->toBeVisible();
        expect($hero->getByRole('option', ['name' => 'feature', 'exact' => true]))->toHaveCount(0);

        $hero->getByRole('option', ['name' => 'component: toolbar', 'exact' => true])->click();
        expect($input)->toHaveValue('component: toolbar');

        $input->fill('zzz');
        expect($hero->locator('text=No tags found.'))->toBeVisible();
    }

    public function testAutocompleteExamples(): void
    {
        $page = $this->visitComponent('autocomplete');

        // Async search: the server searches, and the status gives the number of results.
        $async = $page->getByRole('region', ['name' => 'Async search']);
        $movies = $async->getByRole('combobox', ['name' => 'Search movies by name or year']);
        $movies->fill('god');
        expect($async->locator('text=3 results found'))->toBeVisible();
        $async->getByRole('option', ['name' => 'City of God 2002'])->click();
        expect($movies)->toHaveValue('City of God');
        $movies->fill('will_error');
        expect($async->locator('text=Failed to fetch movies. Please try again.'))->toBeVisible();
        $movies->fill('zzz');
        expect($async->locator('text=Movie or year "zzz" does not exist in the Top 100 IMDb movies'))->toBeVisible();
        $page->keyboard()->press('Escape');

        // Inline: the arrow keys write the label of the highlighted item in the input.
        $inline = $page->getByRole('region', ['name' => 'Inline autocomplete'])->getByRole('combobox', ['name' => 'Search tags']);
        expect($inline)->toHaveAttribute('aria-autocomplete', 'both');
        $inline->pressSequentially('fe');
        $page->keyboard()->press('ArrowDown');
        expect($inline)->toHaveValue('feature');
        $page->keyboard()->press('Escape');
        expect($inline)->toHaveValue('fe');
        $page->keyboard()->press('Escape');

        // Grouped: the filter hides a group with no match.
        $grouped = $page->getByRole('region', ['name' => 'Grouped']);
        $grouped->getByRole('combobox', ['name' => 'Select a tag'])->fill('dial');
        expect($grouped->getByRole('group', ['name' => 'Component']))->toBeVisible();
        expect($grouped->getByRole('group', ['name' => 'Type']))->toBeHidden();
        $page->keyboard()->press('Escape');

        // Fuzzy matching: the characters in order match, and the query is marked.
        $fuzzy = $page->getByRole('region', ['name' => 'Fuzzy matching']);
        $docs = $fuzzy->getByRole('combobox', ['name' => 'Fuzzy search documentation']);
        $docs->fill('rct hks');
        expect($fuzzy->getByRole('option'))->toHaveCount(1);
        expect($fuzzy->getByRole('option', ['name' => 'React Hooks Guide']))->toBeVisible();
        $docs->fill('grid');
        // The title and the description of CSS Grid Layout have the query.
        expect($fuzzy->getByRole('option', ['name' => 'CSS Grid Layout'])->locator('mark'))->toHaveCount(2);
        $docs->fill('zzz');
        expect($fuzzy->locator('text=No results found for "zzz"'))->toBeVisible();
        $page->keyboard()->press('Escape');

        // Limit results: 8 items show, and the status gives the number of hidden items.
        $limit = $page->getByRole('region', ['name' => 'Limit results']);
        $limited = $limit->getByRole('combobox', ['name' => 'Limit results to 8']);
        $limited->fill('component');
        expect($limit->locator('[data-phem-combobox-target="item"]:not([hidden])'))->toHaveCount(8);
        expect($limit->locator('text=Hiding 27 results (type a more specific query to narrow results)'))->toBeVisible();
        $limited->fill('tool');
        expect($limit->locator('[data-phem-combobox-target="item"]:not([hidden])'))->toHaveCount(2);
        expect($limit->locator('text=Hiding'))->toHaveCount(0);
        $page->keyboard()->press('Escape');

        // Auto highlight: the first match gets the highlight as the user types.
        $highlight = $page->getByRole('region', ['name' => 'Auto highlight']);
        $highlight->getByRole('combobox', ['name' => 'Auto highlight on type'])->pressSequentially('d');
        expect($highlight->getByRole('option', ['name' => 'docs', 'exact' => true]))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Escape');

        // Command palette: the inline list always has a highlight, and Enter closes the dialog.
        $palette = $page->getByRole('region', ['name' => 'Command palette']);
        $palette->getByRole('button', ['name' => 'Open command palette'])->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Command palette']);
        $commands = $dialog->getByRole('combobox', ['name' => 'Search commands']);
        expect($commands)->toBeFocused();
        expect($commands)->toHaveAttribute('aria-expanded', 'true');
        expect($dialog->getByRole('option', ['name' => 'Linear Application']))->toHaveAttribute('data-highlighted', '');
        $commands->pressSequentially('tab');
        expect($dialog->getByRole('option', ['name' => 'New Tab Command']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');
        expect($dialog)->toBeHidden();

        // Grid layout: the arrow keys move in the rows and in the columns, and a choice adds the
        // emoji to the message.
        $grid = $page->getByRole('region', ['name' => 'Grid layout']);
        $grid->getByRole('button', ['name' => 'Choose emoji'])->click();
        $emojis = $grid->getByRole('grid', ['name' => 'Emoji results']);
        expect($grid->getByRole('combobox', ['name' => 'Search emojis']))->toBeFocused();
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('ArrowDown');
        expect($emojis->getByRole('gridcell', ['name' => 'rolling on the floor laughing']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');
        expect($grid->getByRole('textbox', ['name' => 'Message']))->toHaveValue('🤣');
        $grid->getByRole('button', ['name' => 'Choose emoji'])->click();
        $grid->getByRole('combobox', ['name' => 'Search emojis'])->fill('cat');
        expect($emojis->getByRole('gridcell'))->toHaveCount(1);
        $page->keyboard()->press('Escape');

        // Virtualized: a click on the input opens the first 50 items, with the total in the status.
        $virtual = $page->getByRole('region', ['name' => 'Virtualized']);
        $virtual->getByRole('combobox', ['name' => 'Search 10,000 items'])->click();
        expect($virtual->getByRole('option'))->toHaveCount(50);
        expect($virtual->locator('text=Showing 50 of 10,000 items. Type to refine.'))->toBeVisible();
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
