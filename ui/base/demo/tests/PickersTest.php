<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class PickersTest extends DemoTestCase
{
    public function testComboboxFiltersTheItemsInTheBrowser(): void
    {
        $page = $this->visitComponent('combobox');
        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');

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
        expect($hero->getByText('No fruits found.'))->toBeVisible();

        // The input has no free text: it gets the label of the selection again.
        $input->blur();
        expect($input)->toHaveValue('Strawberry');

        $clear->click();
        expect($input)->toHaveValue('');
        expect($clear)->toBeHidden();
    }

    public function testComboboxAsyncSearchKeepsANewSelectionOfTheKeyboard(): void
    {
        $page = $this->visitComponent('combobox');
        $async = $page->getByRole('region', ['name' => 'Async search (single)'])->locator('[data-demo="preview"]');
        $reviewer = $async->getByRole('combobox', ['name' => 'Assign reviewer']);
        $clear = $async->getByRole('button', ['name' => 'Clear selection']);
        $lindsay = $async->getByRole('option', ['name' => 'Lindsay Walton']);
        $leslie = $async->getByRole('option', ['name' => 'Leslie Alexander']);

        $reviewer->click();
        $reviewer->fill('lindsay');
        $lindsay->click();
        expect($reviewer)->toHaveValue('Lindsay Walton');

        // The user deletes the text bit by bit, until the search gives a second person.
        $reviewer->click();
        foreach (['Lindsay Walto', 'Lindsay', 'Lin', 'Li'] as $text) {
            $reviewer->fill($text);
            expect($lindsay)->toBeVisible();
        }

        expect($leslie)->toBeVisible();

        // Enter chooses the highlighted person: the name stays after the answer of the server.
        $page->keyboard()->press('ArrowDown');
        expect($leslie)->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');
        expect($reviewer)->toHaveValue('Leslie Alexander');
        // The answer of the server has no person: the other people leave the list.
        expect($async->locator('[role="option"][data-value="lindsay-walton"]'))->toHaveCount(0);
        expect($reviewer)->toHaveValue('Leslie Alexander');
        expect($clear)->toBeVisible();

        $reviewer->click();
        expect($async->getByRole('option'))->toHaveCount(1);
        expect($leslie)->toHaveAttribute('aria-selected', 'true');
    }

    public function testComboboxAsyncSearchKeepsTheTextThatTheUserDeletes(): void
    {
        $page = $this->visitComponent('combobox');
        $async = $page->getByRole('region', ['name' => 'Async search (single)'])->locator('[data-demo="preview"]');
        $reviewer = $async->getByRole('combobox', ['name' => 'Assign reviewer']);
        $lindsay = $async->getByRole('option', ['name' => 'Lindsay Walton']);

        $reviewer->click();
        $reviewer->fill('lindsay');
        $lindsay->click();
        expect($reviewer)->toHaveValue('Lindsay Walton');

        // The user deletes a letter. The answer of the server marks Lindsay as selected (the
        // bound value), and the text of the user stays.
        $reviewer->click();
        $page->keyboard()->press('End');
        $page->evaluate('() => { window.searched = false; window.addEventListener("phem:done", () => { window.searched = true }, { once: true }) }');
        $page->keyboard()->press('Backspace');
        expect($reviewer)->toHaveValue('Lindsay Walto');
        $page->waitForFunction('() => window.searched');
        expect($lindsay)->toBeVisible();
        expect($reviewer)->toHaveValue('Lindsay Walto');
        expect($lindsay)->toHaveAttribute('aria-selected', 'true');
    }

    public function testComboboxAsyncSearchInAFormSendsTheChoicesOnSubmit(): void
    {
        $page = $this->visitComponent('combobox');

        // The page records the elements of the events: the inputs search and the form submits. A
        // choice sends no event.
        $page->evaluate(<<<'JS'
            () => {
                window.senders = []
                document.addEventListener('phem:send', (event) => window.senders.push(event.target.tagName.toLowerCase()), true)
            }
            JS);

        $form = $page->getByRole('region', ['name' => 'Async search in a form'])->locator('[data-demo="preview"]');
        $owner = $form->getByRole('combobox', ['name' => 'Owner']);
        $reviewers = $form->getByRole('combobox', ['name' => 'Reviewers']);
        $submit = $form->getByRole('button', ['name' => 'Submit']);
        $chips = $form->locator('[data-phem-combobox-target="chip"]:not([hidden])');

        // An empty choice: the handler of the submit shows the errors.
        $submit->click();
        expect($form->getByText('Choose an owner.'))->toBeVisible();
        expect($form->getByText('Choose at least one reviewer.'))->toBeVisible();
        expect($owner)->toHaveAttribute('aria-invalid', 'true');

        $owner->fill('tom');
        $form->getByRole('option', ['name' => 'Tom Cook'])->click();
        expect($owner)->toHaveValue('Tom Cook');

        // Each choice adds a chip at once, with no spinner.
        $reviewers->fill('lindsay');
        $form->getByRole('option', ['name' => 'Lindsay Walton'])->click();
        $reviewers->fill('michael');
        $form->getByRole('option', ['name' => 'Michael Foster'])->click();
        expect($chips)->toHaveCount(2);
        expect($chips->nth(0))->toHaveText('Lindsay Walton');
        expect($chips->nth(1))->toHaveText('Michael Foster');
        expect($reviewers)->toHaveValue('');
        expect($form->locator('[data-pending]'))->toHaveCount(0);

        $form->getByRole('button', ['name' => 'Remove Michael Foster'])->click();
        expect($chips)->toHaveCount(1);
        expect($chips)->toHaveText('Lindsay Walton');

        // The submit sends the choices of the browser.
        $submit->click();
        expect($form->getByText('Owner: Tom Cook. Reviewers: Lindsay Walton.'))->toBeVisible();
        expect($form->getByText('Choose an owner.'))->toHaveCount(0);
        expect($chips)->toHaveCount(1);
        expect($chips)->toHaveText('Lindsay Walton');
        expect($owner)->toHaveValue('Tom Cook');

        $senders = $page->evaluate('() => window.senders');
        self::assertSame(['form', 'input'], array_values(array_unique($senders)));
        self::assertSame('form', end($senders));
    }

    public function testComboboxAsyncSearchShowsAChoiceBeforeTheAnswer(): void
    {
        $page = $this->visitComponent('combobox');

        // The page records each state of a choice: a state can be shorter than a round trip of
        // the test.
        $page->evaluate(<<<'JS'
            () => {
                window.choices = []
                const record = () => {
                    for (const el of document.querySelectorAll('[data-pending], [data-failed]')) {
                        const spinner = el.querySelector('.animate-spin')?.checkVisibility() ? 'spinner' : ''
                        const warning = el.querySelector('svg.hidden')?.checkVisibility() ? 'warning' : ''
                        const state = `${el.hasAttribute('data-pending') ? 'pending' : 'failed'} ${el.getAttribute('aria-label')} ${spinner}${warning}`
                        if (!window.choices.includes(state)) window.choices.push(state)
                    }
                }
                new MutationObserver(record).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-pending', 'data-failed'] })
            }
            JS);

        // Multiple: the chip shows at once, with a spinner, and the chip of the server replaces it.
        $multiple = $page->getByRole('region', ['name' => 'Async search (multiple)'])->locator('[data-demo="preview"]');
        $reviewers = $multiple->getByRole('combobox', ['name' => 'Assign reviewers']);
        $tom = $multiple->locator('[data-phem-combobox-target="chip"]')->filter(['hasText' => 'Tom Cook']);
        $reviewers->fill('tom');
        $multiple->getByRole('option', ['name' => 'Tom Cook'])->click();
        expect($tom)->toHaveCount(1);
        expect($tom)->not()->toHaveAttribute('data-pending', '');
        expect($multiple->getByRole('button', ['name' => 'Remove Tom Cook']))->toBeVisible();
        expect($tom->locator('.animate-spin'))->toBeHidden();

        // Marvin is on leave: the chip shows a warning, then it goes after one second.
        $marvin = $multiple->locator('[data-phem-combobox-target="chip"]')->filter(['hasText' => 'Marvin McKinney']);
        $reviewers->fill('marvin');
        $multiple->getByRole('option', ['name' => 'Marvin McKinney'])->click();
        // The first error page of the server can be slow.
        $page->waitForFunction('() => window.choices.includes("failed Marvin McKinney warning") && !document.querySelector("[data-failed]")', null, ['timeout' => 15_000]);
        expect($marvin)->toHaveCount(0);
        expect($tom)->toHaveCount(1);
        expect($multiple->getByRole('option', ['name' => 'Marvin McKinney']))->toHaveCount(0);
        expect($page->locator('.phem-error'))->toHaveCount(0);

        // Single: the clear button shows the spinner while the server assigns the person, and the
        // warning when it fails. Then the selection goes.
        $single = $page->getByRole('region', ['name' => 'Async search (single)'])->locator('[data-demo="preview"]');
        $reviewer = $single->getByRole('combobox', ['name' => 'Assign reviewer']);
        $clear = $single->getByRole('button', ['name' => 'Clear selection']);
        $reviewer->fill('marvin');
        $single->getByRole('option', ['name' => 'Marvin McKinney'])->click();
        expect($reviewer)->toHaveValue('Marvin McKinney');
        $page->waitForFunction('() => window.choices.includes("failed Clear selection warning") && !document.querySelector("[data-failed]")', null, ['timeout' => 15_000]);
        expect($clear)->toBeHidden();
        expect($reviewer)->toHaveValue('');

        $reviewer->fill('tom');
        $single->getByRole('option', ['name' => 'Tom Cook'])->click();
        expect($reviewer)->toHaveValue('Tom Cook');
        $page->waitForFunction('() => window.choices.length === 5 && !document.querySelector("[data-pending]")');
        expect($clear)->not()->toHaveAttribute('data-failed', '');
        expect($clear)->toBeVisible();
        expect($reviewer)->toHaveValue('Tom Cook');

        self::assertSame([
            'pending Tom Cook spinner',
            'pending Marvin McKinney spinner',
            'failed Marvin McKinney warning',
            'pending Clear selection spinner',
            'failed Clear selection warning',
        ], $page->evaluate('() => window.choices'));
    }

    public function testComboboxExamples(): void
    {
        $page = $this->visitComponent('combobox');

        // IDs: the input shows the label of the default value, and the form gets the ID.
        $ids = $page->getByRole('region', ['name' => 'Value selection with IDs'])->locator('[data-demo="preview"]');
        $fruit = $ids->getByRole('combobox', ['name' => 'Choose a fruit']);
        expect($fruit)->toHaveValue('Banana');
        expect($ids->locator('input[name="fruit"]'))->toHaveValue('banana');
        $fruit->fill('man');
        $page->getByRole('option', ['name' => 'Mango'])->click();
        expect($fruit)->toHaveValue('Mango');
        expect($ids->locator('input[name="fruit"]'))->toHaveValue('mango');

        // Multiple: the browser keeps the chosen languages. A click on an item adds a chip, and
        // the popup stays open.
        $multiple = $page->getByRole('region', ['name' => 'Multiple select'])->locator('[data-demo="preview"]');
        $languages = $multiple->getByRole('combobox', ['name' => 'Programming languages']);
        expect($languages)->toHaveAttribute('placeholder', 'e.g. TypeScript');
        expect($multiple->getByRole('button', ['name' => 'Remove TypeScript']))->toHaveCount(0);
        $languages->click();
        $page->getByRole('option', ['name' => 'TypeScript'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove TypeScript']))->toBeVisible();
        expect($page->getByRole('option', ['name' => 'TypeScript']))->toHaveAttribute('aria-selected', 'true');
        expect($languages)->not()->toHaveAttribute('placeholder', 'e.g. TypeScript');
        $page->getByRole('option', ['name' => 'Go'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove Go']))->toBeVisible();
        expect($languages)->toHaveAttribute('aria-description', '2 selected. From the start of the input, press Left Arrow to focus the selected items');

        // A choice with a text clears the text, and the chips keep the order of the choices.
        $languages->fill('java');
        $page->getByRole('option', ['name' => 'JavaScript'])->click();
        expect($languages)->toHaveValue('');
        $chips = $multiple->getByRole('toolbar', ['name' => 'Selected languages'])->locator('[data-phem-combobox-target="chip"]:visible');
        expect($chips)->toHaveCount(3);
        expect($chips->nth(0))->toHaveText('TypeScript');
        expect($chips->nth(1))->toHaveText('Go');
        expect($chips->nth(2))->toHaveText('JavaScript');

        // The remove button of a chip, and Backspace in the empty input, remove a chip.
        $multiple->getByRole('button', ['name' => 'Remove JavaScript'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove JavaScript']))->toHaveCount(0);
        $languages->focus();
        $page->keyboard()->press('Backspace');
        expect($multiple->getByRole('button', ['name' => 'Remove Go']))->toHaveCount(0);
        expect($multiple->getByRole('button', ['name' => 'Remove TypeScript']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // The input in the popup: the trigger opens it with the focus in the input, and a choice
        // shows the label on the trigger.
        $inside = $page->getByRole('region', ['name' => 'Input inside popup'])->locator('[data-demo="preview"]');
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

        // Async search: the handler of the input searches, and the browser keeps the selection
        // and the selected person. A click on the input opens the popup.
        $async = $page->getByRole('region', ['name' => 'Async search (single)'])->locator('[data-demo="preview"]');
        $reviewer = $async->getByRole('combobox', ['name' => 'Assign reviewer']);
        $clear = $async->getByRole('button', ['name' => 'Clear selection']);
        $reviewer->click();
        expect($async->locator('text=Start typing to search people…'))->toBeVisible();
        $reviewer->fill('designer');
        $lindsay = $async->getByRole('option', ['name' => 'Lindsay Walton']);
        // A text with no selection has no clear button.
        expect($clear)->toBeHidden();
        $lindsay->click();
        expect($reviewer)->toHaveValue('Lindsay Walton');
        expect($clear)->toBeVisible();
        // The next open shows only the selected person, with the highlight, and no status.
        $reviewer->click();
        expect($async->getByRole('option'))->toHaveCount(1);
        expect($async->locator('text=Start typing to search people…'))->toBeHidden();
        expect($lindsay)->toHaveAttribute('aria-selected', 'true');
        expect($lindsay)->toHaveAttribute('data-highlighted', '');
        // The selected person stays in the list of a new search.
        $reviewer->fill('zzz');
        expect($async->locator('text=No matches for "zzz".'))->toBeVisible();
        expect($lindsay)->toBeVisible();
        expect($async->locator('text=Try a different search term.'))->toBeHidden();
        // The input has no free text: Escape gives back the name of the selection.
        $page->keyboard()->press('Escape');
        expect($reviewer)->toHaveValue('Lindsay Walton');
        // A second Escape, on the closed popup, removes the text and the selection.
        $page->keyboard()->press('Escape');
        expect($reviewer)->toHaveValue('');
        expect($clear)->toBeHidden();
        $reviewer->fill('designer');
        $lindsay->click();
        // The clear button removes the text and the selection: a closed popup stays closed.
        $clear->click();
        expect($reviewer)->toHaveValue('');
        expect($clear)->toBeHidden();
        expect($reviewer)->toBeFocused();
        expect($reviewer)->toHaveAttribute('aria-expanded', 'false');
        // On an open popup, the popup stays open, with no person and the idle status.
        $reviewer->fill('designer');
        $lindsay->click();
        $reviewer->fill('Lindsay Waltonx');
        expect($async->locator('text=No matches for "Lindsay Waltonx".'))->toBeVisible();
        $clear->click();
        expect($reviewer)->toHaveValue('');
        expect($reviewer)->toHaveAttribute('aria-expanded', 'true');
        expect($async->getByRole('option'))->toHaveCount(0);
        expect($async->locator('text=Start typing to search people…'))->toBeVisible();
        $page->keyboard()->press('Escape');
        // A text that the user deletes in one go removes the selection too.
        $reviewer->fill('designer');
        $lindsay->click();
        expect($reviewer)->toHaveValue('Lindsay Walton');
        $reviewer->fill('');
        expect($clear)->toBeHidden();
        expect($async->locator('text=Start typing to search people…'))->toBeVisible();
        expect($async->getByRole('option'))->toHaveCount(0);
        $page->keyboard()->press('Escape');
        expect($reviewer)->toHaveValue('');
        $reviewer->fill('zzz');
        expect($async->locator('text=Try a different search term.'))->toBeVisible();
        // The handler throws: the status shows the error, with no item and no message of phem.
        $reviewer->fill('will_error');
        expect($async->locator('text=Failed to fetch people. Please try again.'))->toBeVisible();
        expect($async->getByRole('option'))->toHaveCount(0);
        expect($page->locator('.phem-error'))->toHaveCount(0);
        $reviewer->fill('tom');
        expect($async->getByRole('option', ['name' => 'Tom Cook']))->toBeVisible();
        expect($async->locator('text=Failed to fetch people. Please try again.'))->toBeHidden();
        $page->keyboard()->press('Escape');
        expect($reviewer)->toHaveValue('');

        // Async search with chips. A click on the input opens the popup.
        $multiple = $page->getByRole('region', ['name' => 'Async search (multiple)'])->locator('[data-demo="preview"]');
        $reviewers = $multiple->getByRole('combobox', ['name' => 'Assign reviewers']);
        $reviewers->click();
        expect($multiple->locator('text=Start typing to search people…'))->toBeVisible();
        // A choice with a text clears the text and closes the popup.
        $reviewers->fill('lindsay');
        $multiple->getByRole('option', ['name' => 'Lindsay Walton'])->click();
        expect($multiple->getByRole('button', ['name' => 'Remove Lindsay Walton']))->toBeVisible();
        expect($reviewers)->toHaveValue('');
        expect($reviewers)->toHaveAttribute('aria-expanded', 'false');
        expect($reviewers)->toHaveAttribute('aria-description', '1 selected. From the start of the input, press Left Arrow to focus the selected items');
        // The next open shows the chosen people, with the highlight on the first one.
        $reviewers->click();
        $chosen = $multiple->getByRole('option', ['name' => 'Lindsay Walton']);
        expect($multiple->getByRole('option'))->toHaveCount(1);
        expect($chosen)->toHaveAttribute('aria-selected', 'true');
        expect($chosen)->toHaveAttribute('data-highlighted', '');
        // A text that the user deletes in one go keeps the chips: the popup shows the chosen
        // people, with the highlight on the first one.
        $reviewers->fill('tom');
        expect($multiple->getByRole('option', ['name' => 'Tom Cook']))->toBeVisible();
        expect($multiple->getByRole('option'))->toHaveCount(3);
        $reviewers->fill('');
        expect($multiple->getByRole('option'))->toHaveCount(1);
        expect($chosen)->toHaveAttribute('data-highlighted', '');
        expect($reviewers)->toHaveAttribute('aria-expanded', 'true');
        expect($multiple->locator('text=Start typing to search people…'))->toBeHidden();
        expect($multiple->getByRole('button', ['name' => 'Remove Lindsay Walton']))->toBeVisible();
        // The input has no free text: Escape clears it, and the chips stay.
        $reviewers->fill('zzz');
        expect($multiple->locator('text=No matches for "zzz".'))->toBeVisible();
        expect($chosen)->toBeVisible();
        $page->keyboard()->press('Escape');
        expect($reviewers)->toHaveValue('');
        expect($multiple->getByRole('button', ['name' => 'Remove Lindsay Walton']))->toBeVisible();
        // A second Escape, on the closed popup, removes all the chips.
        $page->keyboard()->press('Escape');
        expect($multiple->getByRole('button', ['name' => 'Remove Lindsay Walton']))->toHaveCount(0);
        expect($reviewers)->toHaveAttribute('placeholder', 'e.g. Michael');
        $reviewers->fill('zzz');
        expect($multiple->locator('text=Try a different search term.'))->toBeVisible();
        $reviewers->fill('will_error');
        expect($multiple->locator('text=Failed to fetch people. Please try again.'))->toBeVisible();
        expect($page->locator('.phem-error'))->toHaveCount(0);
        $page->keyboard()->press('Escape');

        // Creatable: a new text shows a Create item, which opens a dialog with the text.
        $creatable = $page->getByRole('region', ['name' => 'Creatable'])->locator('[data-demo="preview"]');
        $labels = $creatable->getByRole('combobox', ['name' => 'Labels']);
        $labels->fill('urgent');
        $creatable->getByRole('option', ['name' => 'Create "urgent"'])->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Create new label']);
        expect($dialog)->toBeVisible();
        expect($dialog->getByRole('textbox', ['name' => 'Label name']))->toHaveValue('urgent');
        $dialog->getByRole('button', ['name' => 'Create'])->click();
        expect($dialog)->toBeHidden();
        expect($creatable->getByRole('button', ['name' => 'Remove urgent']))->toBeVisible();

        // Large datasets: the server renders the first 50 matches, and the status gives the total.
        $large = $page->getByRole('region', ['name' => 'Large datasets'])->locator('[data-demo="preview"]');
        $items = $large->getByRole('combobox', ['name' => 'Search 10,000 items']);
        $items->fill('1');
        expect($large->locator('[data-phem-combobox-target="item"]'))->toHaveCount(50);
        expect($large->locator('text=Showing 50 of'))->toBeVisible();
        $items->fill('Item 0042');
        expect($large->getByRole('option', ['name' => 'Item 0042']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // Grouped: the filter hides a group with no match.
        $produce = $page->getByRole('region', ['name' => 'Grouped'])->locator('[data-demo="preview"]')->getByRole('combobox', ['name' => 'Select produce']);
        $produce->fill('ka');
        expect($page->getByRole('group', ['name' => 'Vegetables']))->toBeVisible();
        expect($page->getByRole('group', ['name' => 'Fruits']))->toBeHidden();
        expect($page->getByRole('option', ['name' => 'Kale']))->toBeVisible();
    }

    public function testAutocompleteFiltersTheTagsInTheBrowser(): void
    {
        $page = $this->visitComponent('autocomplete');

        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');
        $input = $hero->getByRole('combobox', ['name' => 'Search tags']);

        // The input shows its focus ring, as the input of the Base UI demo.
        $input->focus();
        expect($input)->toHaveCSS('outline-style', 'solid');
        expect($input)->toHaveCSS('outline-width', '2px');

        // The browser does all the steps below: no request goes to the server.
        $resources = '() => performance.getEntriesByType("resource").length';
        $requests = $page->evaluate($resources);

        // The input has the attributes of the Base UI input, and points to the list only while
        // the popup is open.
        expect($input)->toHaveAttribute('spellcheck', 'false');
        expect($input)->toHaveAttribute('autocapitalize', 'none');
        expect($hero->locator('#tags-input:not([aria-controls])'))->toHaveCount(1);

        // A click does not open the popup. ArrowDown opens it with all the tags and no highlight.
        $input->click();
        expect($input)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveAttribute('aria-expanded', 'true');
        expect($hero->locator('#tags-input[aria-controls]'))->toHaveCount(1);
        expect($hero->getByRole('option'))->toHaveCount(41);
        expect($hero->locator('[data-highlighted]'))->toHaveCount(0);

        $input->pressSequentially('too');
        expect($hero->getByRole('option', ['name' => 'component: tooltip', 'exact' => true]))->toBeVisible();
        expect($hero->getByRole('option', ['name' => 'component: toolbar', 'exact' => true]))->toBeVisible();
        expect($hero->getByRole('option', ['name' => 'feature', 'exact' => true]))->toHaveCount(0);

        // An Autocomplete has no selection: the items have no aria-selected.
        $toolbar = $hero->getByRole('option', ['name' => 'component: toolbar', 'exact' => true]);
        $tooltip = $hero->getByRole('option', ['name' => 'component: tooltip', 'exact' => true]);
        $page->keyboard()->press('ArrowDown');
        expect($toolbar)->toHaveAttribute('data-highlighted', '');
        expect($hero->locator('[role=option][aria-selected]'))->toHaveCount(0);

        // ArrowUp from the first item goes back to the input, then to the last item.
        $page->keyboard()->press('ArrowUp');
        expect($hero->locator('[data-highlighted]'))->toHaveCount(0);
        $page->keyboard()->press('ArrowUp');
        expect($tooltip)->toHaveAttribute('data-highlighted', '');

        // Enter writes the label in the input, closes the popup and keeps the focus.
        $page->keyboard()->press('Enter');
        expect($input)->toHaveValue('component: tooltip');
        expect($input)->toHaveAttribute('aria-expanded', 'false');
        expect($input)->toBeFocused();

        // Escape closes the popup, and a second Escape clears the input.
        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');
        expect($input)->toHaveAttribute('aria-expanded', 'false');
        expect($input)->toHaveValue('component: tooltip');
        $page->keyboard()->press('Escape');
        expect($input)->toHaveValue('');

        // A click on an item writes its label in the input.
        $input->pressSequentially('too');
        $toolbar->click();
        expect($input)->toHaveValue('component: toolbar');
        expect($input)->toHaveAttribute('aria-expanded', 'false');

        $input->fill('zzz');
        expect($hero->locator('text=No tags found.'))->toBeVisible();

        self::assertSame($requests, $page->evaluate($resources));
    }

    public function testAutocompleteExamples(): void
    {
        $page = $this->visitComponent('autocomplete');

        // Async search: the handler of the input searches, and the status shows the state of the
        // search. With no text, the popup does not open.
        $async = $page->getByRole('region', ['name' => 'Async search'])->locator('[data-demo="preview"]');
        $movies = $async->getByRole('combobox', ['name' => 'Search movies by name or year']);
        $movies->focus();
        $page->keyboard()->press('ArrowDown');
        expect($movies)->toHaveAttribute('aria-expanded', 'false');

        $movies->pressSequentially('god');
        expect($async->locator('text=3 results found'))->toBeVisible();
        expect($async->locator('text=Searching…'))->toBeHidden();
        expect($async->getByRole('option'))->toHaveCount(3);
        $page->keyboard()->press('ArrowDown');
        expect($async->getByRole('option', ['name' => 'The Godfather 1972']))->toHaveAttribute('data-highlighted', '');

        // A choice writes the title in the input and closes the popup. The title is the next
        // search.
        $async->getByRole('option', ['name' => 'City of God 2002'])->click();
        expect($movies)->toHaveValue('City of God');
        expect($movies)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('ArrowDown');
        expect($async->locator('text=1 result found'))->toBeVisible();
        expect($async->getByRole('option'))->toHaveCount(1);

        $movies->fill('will_error');
        expect($async->locator('text=Failed to fetch movies. Please try again.'))->toBeVisible();
        expect($async->getByRole('option'))->toHaveCount(0);
        $movies->fill('zzz');
        expect($async->locator('text=Movie or year "zzz" does not exist in the Top 100 IMDb movies'))->toBeVisible();

        // Escape closes the popup, and a second Escape clears the input.
        $page->keyboard()->press('Escape');
        expect($movies)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('Escape');
        expect($movies)->toHaveValue('');

        // Inline: the arrow keys write the label of the highlighted item in the input, and the list
        // stays filtered on the typed text.
        $inlineDemo = $page->getByRole('region', ['name' => 'Inline autocomplete'])->locator('[data-demo="preview"]');
        $inline = $inlineDemo->getByRole('combobox', ['name' => 'Search tags']);
        expect($inline)->toHaveAttribute('aria-autocomplete', 'both');
        $inline->pressSequentially('co');
        expect($inlineDemo->getByRole('option'))->toHaveCount(35);
        $page->keyboard()->press('ArrowDown');
        expect($inline)->toHaveValue('component: accordion');
        $page->keyboard()->press('ArrowDown');
        expect($inline)->toHaveValue('component: alert dialog');
        expect($inlineDemo->getByRole('option'))->toHaveCount(35);

        // Up from the first item goes back to the typed text, then wraps to the last item.
        $page->keyboard()->press('ArrowUp');
        $page->keyboard()->press('ArrowUp');
        expect($inline)->toHaveValue('co');
        expect($inlineDemo->locator('[data-highlighted]'))->toHaveCount(0);
        $page->keyboard()->press('ArrowUp');
        expect($inline)->toHaveValue('component: tooltip');

        // Escape closes the popup and puts back the typed text.
        $page->keyboard()->press('Escape');
        expect($inline)->toHaveValue('co');
        expect($inline)->toHaveAttribute('aria-expanded', 'false');

        // Enter writes the highlighted item in the input and closes the popup.
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($inline)->toHaveValue('component: accordion');
        expect($inline)->toHaveAttribute('aria-expanded', 'false');

        // No match: the first Escape keeps the text, the second clears it, and the popup stays
        // closed.
        $inline->fill('zzz');
        $page->keyboard()->press('Escape');
        expect($inline)->toHaveValue('zzz');
        $page->keyboard()->press('Escape');
        expect($inline)->toHaveValue('');
        expect($inline)->toHaveAttribute('aria-expanded', 'false');

        // Grouped: the browser does all the steps: no request goes to the server.
        $grouped = $page->getByRole('region', ['name' => 'Grouped'])->locator('[data-demo="preview"]');
        $tag = $grouped->getByRole('combobox', ['name' => 'Select a tag']);
        $resources = '() => performance.getEntriesByType("resource").length';
        $requests = $page->evaluate($resources);

        // The input has the attributes of the Base UI input.
        expect($tag)->toHaveAttribute('spellcheck', 'false');

        // The filter hides a group with no match. The group labels name their groups, and screen
        // readers do not read them again.
        $tag->fill('co');
        expect($grouped->getByRole('group', ['name' => 'Component']))->toBeVisible();
        expect($grouped->getByRole('group', ['name' => 'Type']))->toBeHidden();
        expect($grouped->getByRole('option'))->toHaveCount(35);
        expect($grouped->locator('[data-phem-combobox-target="groupLabel"]')->first())->toHaveAttribute('aria-hidden', 'true');

        // The arrow keys go across the groups and skip the labels. An item of an autocomplete has
        // no aria-selected.
        $tag->fill('o');
        $page->keyboard()->press('ArrowDown');
        expect($grouped->getByRole('option', ['name' => 'docs']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        expect($grouped->getByRole('option', ['name' => 'component: accordion']))->toHaveAttribute('data-highlighted', '');
        expect($grouped->locator('[role="option"][aria-selected]'))->toHaveCount(0);

        // Enter writes the label in the input and closes the popup.
        $page->keyboard()->press('Enter');
        expect($tag)->toHaveValue('component: accordion');
        expect($tag)->toHaveAttribute('aria-expanded', 'false');

        // No match: the popup shows the empty text. The first Escape closes the popup and keeps the
        // text, and the second clears the input.
        $tag->fill('zzz');
        expect($grouped->locator('text=No tags found.'))->toBeVisible();
        $page->keyboard()->press('Escape');
        expect($tag)->toHaveValue('zzz');
        expect($tag)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('Escape');
        expect($tag)->toHaveValue('');

        // ArrowUp opens the popup with no highlight, and the next ArrowUp highlights the last item.
        $page->keyboard()->press('ArrowUp');
        expect($tag)->toHaveAttribute('aria-expanded', 'true');
        expect($grouped->locator('[data-highlighted]'))->toHaveCount(0);
        $page->keyboard()->press('ArrowUp');
        expect($grouped->getByRole('option', ['name' => 'component: tooltip']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Escape');

        // An input that the user empties closes the popup.
        $tag->fill('f');
        expect($tag)->toHaveAttribute('aria-expanded', 'true');
        $tag->fill('');
        expect($tag)->toHaveAttribute('aria-expanded', 'false');

        // A click on an item writes its label in the input, and the focus stays in the input.
        $tag->fill('co');
        $grouped->getByRole('option', ['name' => 'component: avatar'])->click();
        expect($tag)->toHaveValue('component: avatar');
        expect($tag)->toBeFocused();

        self::assertSame($requests, $page->evaluate($resources));

        // Fuzzy matching: the browser matches the characters of the query in order, in the label
        // and in each keyword of an item. The items keep the order of the data, and the browser
        // marks the text that has the query. No request goes to the server.
        $requests = $page->evaluate($resources);
        $fuzzy = $page->getByRole('region', ['name' => 'Fuzzy matching'])->locator('[data-demo="preview"]');
        $docs = $fuzzy->getByRole('combobox', ['name' => 'Fuzzy search documentation']);
        $results = $fuzzy->getByRole('option');
        $docs->pressSequentially('rct hks');
        expect($results)->toHaveCount(1);
        expect($results->first())->toContainText('React Hooks Guide');
        expect($results->first()->locator('mark'))->toHaveCount(0);

        // The title and the description of CSS Grid Layout have the query.
        $docs->fill('grid');
        expect($results)->toHaveCount(7);
        expect($results->first())->toContainText('CSS Flexbox Layout');
        $marks = $fuzzy->getByRole('option', ['name' => 'CSS Grid Layout'])->locator('mark');
        expect($marks)->toHaveCount(2);
        expect($marks->first())->toHaveText('Grid');
        expect($marks->last())->toHaveText('Grid');

        // The category is a keyword.
        $docs->fill('database');
        expect($results)->toHaveCount(1);
        expect($results->first())->toContainText('MongoDB Queries');

        // Enter writes the title in the input, and the next open marks all the title.
        $docs->fill('react');
        expect($results)->toHaveCount(7);
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($docs)->toHaveValue('React Hooks Guide');
        expect($docs)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('ArrowDown');
        expect($results)->toHaveCount(1);
        expect($results->first()->locator('mark')->first())->toHaveText('React Hooks Guide');
        $page->keyboard()->press('Escape');

        $docs->fill('zzz');
        expect($fuzzy->locator('text=No results found for "zzz"'))->toBeVisible();
        $page->keyboard()->press('Escape');
        $page->keyboard()->press('Escape');
        expect($docs)->toHaveValue('');
        self::assertSame($requests, $page->evaluate($resources));

        // Limit results: 8 items show, and the browser counts the hidden matches for the status.
        // The browser filters: no request.
        $requests = $page->evaluate($resources);
        $limit = $page->getByRole('region', ['name' => 'Limit results'])->locator('[data-demo="preview"]');
        $limited = $limit->getByRole('combobox', ['name' => 'Limit results to 8']);
        $items = $limit->locator('[data-phem-combobox-target="item"]:not([hidden])');

        // With no text, ArrowDown opens the popup: 50 tags, 8 show.
        $limited->focus();
        $page->keyboard()->press('ArrowDown');
        expect($items)->toHaveCount(8);
        expect($limit->getByText('Hiding 42 results (type a more specific query to narrow results)'))->toBeVisible();

        $limited->fill('component');
        expect($items)->toHaveCount(8);
        expect($limit->getByText('Hiding 27 results (type a more specific query to narrow results)'))->toBeVisible();

        $limited->fill('tool');
        expect($items)->toHaveCount(2);
        expect($limit->getByText('Hiding'))->toHaveCount(0);

        $limited->fill('compzz');
        expect($items)->toHaveCount(0);
        expect($limit->getByText('No results found for "compzz"'))->toBeVisible();

        // A choice writes the label of the item in the input.
        $limited->fill('tool');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($limited)->toHaveValue('component: tooltip');
        expect($limited)->toHaveAttribute('aria-expanded', 'false');
        self::assertSame($requests, $page->evaluate($resources));

        // Auto highlight: the first match gets the highlight as the user types. The browser does
        // all the steps: no request goes to the server.
        $requests = $page->evaluate($resources);
        $highlight = $page->getByRole('region', ['name' => 'Auto highlight'])->locator('[data-demo="preview"]');
        $tags = $highlight->getByRole('combobox', ['name' => 'Auto highlight on type']);
        $first = $highlight->getByRole('option', ['name' => 'component: accordion', 'exact' => true]);
        $tags->pressSequentially('comp');
        expect($first)->toHaveAttribute('data-highlighted', '');
        expect($tags)->toHaveAttribute('aria-activedescendant', 'highlighted-tags-item-c-accordion');

        // The keys loop: ArrowUp on the first item goes to the last item.
        $page->keyboard()->press('ArrowUp');
        expect($highlight->getByRole('option', ['name' => 'component: tooltip', 'exact' => true]))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('ArrowDown');
        expect($first)->toHaveAttribute('data-highlighted', '');

        // The pointer moves the highlight. When the pointer leaves the list, the highlight goes,
        // and ArrowDown starts again at the first item.
        $autocomplete = $highlight->getByRole('option', ['name' => 'component: autocomplete', 'exact' => true]);
        $autocomplete->hover();
        expect($autocomplete)->toHaveAttribute('data-highlighted', '');
        $page->mouse()->move(5, 5);
        expect($highlight->locator('[role="option"][data-highlighted]'))->toHaveCount(0);
        expect($highlight->locator('input[aria-activedescendant]'))->toHaveCount(0);
        $page->keyboard()->press('ArrowDown');
        expect($first)->toHaveAttribute('data-highlighted', '');

        // A new character gives the highlight to the first match again.
        $page->keyboard()->press('ArrowDown');
        $tags->pressSequentially('onent: to');
        expect($highlight->getByRole('option', ['name' => 'component: toast', 'exact' => true]))->toHaveAttribute('data-highlighted', '');

        // An input that the user empties closes the popup.
        $tags->fill('');
        expect($tags)->toHaveAttribute('aria-expanded', 'false');

        // Enter writes the highlighted item in the input and closes the popup.
        $tags->pressSequentially('fe');
        $page->keyboard()->press('Enter');
        expect($tags)->toHaveValue('feature');
        expect($tags)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('Escape');
        expect($tags)->toHaveValue('');
        self::assertSame($requests, $page->evaluate($resources));

        // Command palette: the inline list always has a highlight, the list scrolls, and a choice
        // closes the dialog. The browser filters and chooses: no request goes to the server.
        $palette = $page->getByRole('region', ['name' => 'Command palette'])->locator('[data-demo="preview"]');
        $fetches = "() => performance.getEntriesByType('resource').filter((e) => e.initiatorType === 'fetch').length";
        $sent = $page->evaluate($fetches);
        $open = $palette->getByRole('button', ['name' => 'Open command palette']);
        $open->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Command palette']);
        $commands = $dialog->getByRole('combobox', ['name' => 'Search commands']);
        $linear = $dialog->getByRole('option', ['name' => 'Linear Application']);
        expect($commands)->toBeFocused();
        expect($commands)->toHaveAttribute('aria-expanded', 'true');
        expect($linear)->toHaveAttribute('data-highlighted', '');
        expect($linear)->not()->toHaveAttribute('aria-selected', 'true');

        // ArrowUp goes to the last command, and the list scrolls to it.
        $page->keyboard()->press('ArrowUp');
        $last = $dialog->getByRole('option', ['name' => 'Run Script Command']);
        expect($last)->toHaveAttribute('data-highlighted', '');
        // The item is in the visible box of the scroll area.
        $page->waitForFunction(<<<'JS'
            () => {
                const item = document.querySelector('#palette-item-run-script').getBoundingClientRect();
                const box = document.querySelector('#palette-item-run-script').closest('[data-controller~="phem-scroll-area"]').getBoundingClientRect();

                return item.top >= box.top && item.bottom <= box.bottom;
            }
            JS);

        // The filter uses the label of the item, not its kind.
        $commands->fill('command');
        expect($dialog->locator('text=No results found.'))->toBeVisible();
        $commands->fill('tab');
        expect($dialog->getByRole('option', ['name' => 'New Tab Command']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');
        expect($dialog)->toBeHidden();
        expect($open)->toBeFocused();

        // Each open starts with an empty search.
        $open->click();
        expect($commands)->toHaveValue('');
        expect($linear)->toHaveAttribute('data-highlighted', '');

        // A click on an item closes the dialog, and Escape closes it too.
        $dialog->getByRole('option', ['name' => 'Slack Application'])->click();
        expect($dialog)->toBeHidden();
        $open->click();
        $commands->pressSequentially('fig');
        $page->keyboard()->press('Escape');
        expect($dialog)->toBeHidden();
        self::assertSame($sent, $page->evaluate($fetches));

        // Grid layout: the browser filters the emojis and fills the rows again, and a choice
        // inserts the emoji at the caret of the message, with no request to the server.
        $grid = $page->getByRole('region', ['name' => 'Grid layout'])->locator('[data-demo="preview"]');
        $message = $grid->getByRole('textbox', ['name' => 'Message']);
        $search = $grid->getByRole('combobox', ['name' => 'Search emojis']);
        $emojis = $grid->getByRole('grid', ['name' => 'Emoji results']);
        $sent = $page->evaluate($fetches);
        $message->fill('Hi there');
        $message->evaluate('(el) => el.setSelectionRange(2, 2)');
        $grid->getByRole('button', ['name' => 'Choose emoji'])->click();
        expect($search)->toBeFocused();
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('ArrowDown');
        expect($emojis->getByRole('gridcell', ['name' => '🤣']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');
        expect($message)->toHaveValue('Hi🤣 there');
        expect($message)->toBeFocused();
        // The caret is after the emoji: 2 characters and the 2 UTF-16 units of the emoji.
        self::assertSame(4, $message->evaluate('(el) => el.selectionStart'));

        // The search starts with no text at the next open, with all the emojis.
        $grid->getByRole('button', ['name' => 'Choose emoji'])->click();
        expect($search)->toHaveValue('');
        expect($emojis->getByRole('gridcell'))->toHaveCount(90);

        // "fa" gives 9 animals in rows of 5 and 4, and hides the food.
        $search->fill('fa');
        $animals = $emojis->getByRole('group', ['name' => 'Animals & Nature']);
        expect($animals->getByRole('row'))->toHaveCount(2);
        expect($animals->getByRole('row')->nth(0)->getByRole('gridcell'))->toHaveCount(5);
        expect($animals->getByRole('row')->nth(1)->getByRole('gridcell'))->toHaveCount(4);
        expect($emojis->getByRole('group', ['name' => 'Food & Drink']))->toBeHidden();

        // The arrow keys move in the new rows: "monkey" gives one row of 5.
        $search->fill('monkey');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('ArrowRight');
        $page->keyboard()->press('ArrowRight');
        expect($emojis->getByRole('gridcell', ['name' => '🐒']))->toHaveAttribute('data-highlighted', '');

        $search->fill('zzz');
        expect($grid->getByText('No emojis found'))->toBeVisible();
        expect($emojis->getByRole('gridcell'))->toHaveCount(0);
        $page->keyboard()->press('Escape');
        self::assertSame($sent, $page->evaluate($fetches));

        // Virtualized: a click on the input opens the first 50 items, with the total in the status.
        $virtualized = $page->getByRole('region', ['name' => 'Virtualized'])->locator('[data-demo="preview"]');
        $many = $virtualized->getByRole('combobox', ['name' => 'Search 10,000 items']);
        $many->click();
        expect($virtualized->getByRole('option'))->toHaveCount(50);
        expect($virtualized->getByRole('option')->first())->toHaveText('Item 0001');
        expect($virtualized->getByText('Showing 50 of 10,000 items. Type to refine.'))->toBeVisible();

        // The onChange handler searches on the server.
        $many->fill('42');
        expect($virtualized->getByText('Showing 50 of 299 items. Type to refine.'))->toBeVisible();
        expect($virtualized->getByRole('option')->first())->toHaveText('Item 0042');

        // A choice writes the label in the input, with no handler of the item: one search of the
        // label goes to the server.
        $sent = $page->evaluate($fetches);
        $virtualized->getByRole('option', ['name' => 'Item 0142'])->click();
        expect($many)->toHaveValue('Item 0142');
        expect($many)->toHaveAttribute('aria-expanded', 'false');
        $many->click();
        expect($virtualized->getByRole('option'))->toHaveCount(1);
        expect($virtualized->getByRole('option', ['name' => 'Item 0142']))->toBeVisible();
        self::assertSame($sent + 1, $page->evaluate($fetches));
        expect($virtualized->getByText('Showing 50 of'))->toBeHidden();

        // The keyboard: ArrowDown highlights the first match, and Enter writes its label.
        $many->fill('99');
        expect($virtualized->getByRole('option')->first())->toHaveText('Item 0099');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($many)->toHaveValue('Item 0099');

        // No match: the empty text. Escape closes the popup, then clears the input.
        $many->fill('zzz');
        expect($virtualized->getByText('No items found.'))->toBeVisible();
        $page->keyboard()->press('Escape');
        expect($many)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('Escape');
        expect($many)->toHaveValue('');
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
        $formatted = $page->getByRole('region', ['name' => 'Formatting the value'])->locator('[data-demo="preview"]');
        expect($formatted->getByRole('combobox', ['name' => 'Theme']))->toHaveText('System default');
        $labeled = $page->getByRole('region', ['name' => 'Labeling a select'])->locator('[data-demo="preview"]');
        $labeled->getByText('Theme')->click();
        expect($labeled->getByRole('combobox', ['name' => 'Theme']))->toBeFocused();
        expect($page->getByRole('listbox'))->toBeHidden();

        // The placeholder.
        expect($page->getByRole('region', ['name' => 'Placeholder values'])->locator('[data-demo="preview"]')->getByRole('combobox', ['name' => 'Theme']))->toHaveAttribute('data-placeholder', '');

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
        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');

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
        $both = $page->getByRole('region', ['name' => 'Both scrollbars'])->locator('[data-demo="preview"]')->locator('[data-controller~="phem-scroll-area"]');
        expect($both)->toHaveAttribute('data-has-overflow-x', '');
        expect($both)->toHaveAttribute('data-has-overflow-y', '');
        expect($both->locator('[data-phem-scroll-area-target="corner"]'))->not()->toHaveAttribute('hidden', '');

        // The fade reads the overflow variables of the viewport.
        $fade = $page->getByRole('region', ['name' => 'Gradient scroll fade'])->locator('[data-demo="preview"]')->locator('[data-phem-scroll-area-target="viewport"]');
        self::assertSame('0px', $fade->evaluate('el => getComputedStyle(el).getPropertyValue("--scroll-area-overflow-y-start").trim()'));
        $fade->evaluate('(el) => el.scrollTo(0, 100)');
        expect($fade)->toHaveCSS('--scroll-area-overflow-y-start', '100px');

        // A tab list in a viewport: it scrolls, and the tabs still work.
        $tabs = $page->getByRole('region', ['name' => 'Combining with Tabs'])->locator('[data-demo="preview"]');
        expect($tabs->locator('[data-controller~="phem-scroll-area"]'))->toHaveAttribute('data-has-overflow-x', '');
        $tabs->getByRole('tab', ['name' => 'Notifications'])->click();
        expect($tabs->getByRole('tabpanel'))->toHaveText('Notifications');
    }

    public function testToastShowsOneToastForEachClick(): void
    {
        $page = $this->visitComponent('toast');

        // A long duration: the test does not depend on the time of a toast. The first viewport is
        // the viewport of the hero.
        $stack = $page->locator('[data-controller~="phem-toast"]')->first();
        $stack->evaluate('(el) => el.setAttribute("data-phem-toast-duration-value", "60000")');

        $button = $page->getByRole('region', ['name' => 'Demo', 'exact' => true])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Create toast']);
        $button->click();
        expect($stack->getByText('Toast 1 created'))->toBeVisible();
        expect($stack->getByText('This is a toast notification.'))->toBeVisible();

        // The text of the toast behind the new one hides, until the pointer is on the stack.
        $button->click();
        expect($stack->getByText('Toast 2 created'))->toBeVisible();
        expect($page->locator('[data-phem-toast-content][data-behind]'))->toHaveCount(1);
        expect($page->locator('[data-phem-toast-content][data-behind]'))->toHaveCSS('opacity', '0');

        $stack->getByText('Toast 2 created')->hover();
        expect($page->locator('[data-phem-toast-content][data-behind]'))->toHaveCSS('opacity', '1');
    }

    public function testF6MovesTheFocusToTheToastRegion(): void
    {
        $page = $this->visitComponent('toast');

        $region = $page->getByRole('region', ['name' => 'Notifications'])->first();
        $region->evaluate('(el) => el.setAttribute("data-phem-toast-duration-value", "60000")');

        $page->getByRole('region', ['name' => 'Demo', 'exact' => true])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Create toast'])->click();
        // Each toast is a dialog that is not modal, as in Base UI.
        expect($region->getByRole('dialog'))->toHaveCount(1);

        $page->keyboard()->press('F6');
        expect($region)->toBeFocused();
    }

    public function testToastExamples(): void
    {
        $page = $this->visitComponent('toast');
        $region = fn (string $name) => $page->getByRole('region', ['name' => $name, 'exact' => true])->locator('[data-demo="preview"]');
        $viewport = fn (string $name) => $page->locator('[data-phem-toast-name-value="'.$name.'"]');
        $main = $page->locator('[data-controller~="phem-toast"]:not([data-phem-toast-name-value])');
        $main->evaluate('(el) => el.setAttribute("data-phem-toast-duration-value", "60000")');

        // Anchored toasts: the toast shows above the copy button, and the button has its check icon
        // until the toast goes.
        $page->context()->grantPermissions(['clipboard-read', 'clipboard-write']);
        $copy = $page->getByRole('button', ['name' => 'Copy to clipboard']);
        $copy->click();
        $anchored = $viewport('anchored')->locator('[data-phem-toast]');
        expect($anchored)->toHaveText('Copied');
        expect($anchored)->toHaveAttribute('data-side', 'top');
        expect($anchored->locator('[data-phem-toast-arrow]'))->toHaveCount(0);
        self::assertTrue($anchored->evaluate('(el) => el.getBoundingClientRect().bottom <= document.getElementById("toast-copy").getBoundingClientRect().top'));
        expect($copy)->toHaveAttribute('data-copied', '');
        expect($anchored)->toHaveCount(0, ['timeout' => 5000]);
        expect($copy)->not()->toHaveAttribute('data-copied', '');

        // Custom position: the stack is at the top of the screen.
        $region('Custom position')->getByRole('button', ['name' => 'Create toast'])->click();
        $top = $viewport('top')->locator('[data-phem-toast]');
        expect($top)->toHaveCount(1);
        self::assertTrue($top->evaluate('(el) => el.getBoundingClientRect().top < innerHeight / 2'));

        // Undo action: the action button calls the handler, which shows another toast.
        $region('Undo action')->getByRole('button', ['name' => 'Perform action'])->click();
        $undo = $viewport('undo')->getByRole('button', ['name' => 'Undo']);
        expect($viewport('undo')->getByText('Action performed'))->toBeVisible();
        // The stack expands under the pointer: the button of the toast is in front.
        $viewport('undo')->getByText('Action performed')->hover();
        $undo->click();
        expect($viewport('undo')->getByText('Action undone'))->toBeVisible();
        expect($viewport('undo')->getByText('Action performed'))->toHaveCount(0);

        // Promise: the loading toast of the click becomes the toast of the answer.
        $region('Promise')->getByRole('button', ['name' => 'Run promise'])->click();
        expect($main->getByText('Loading data…'))->toBeAttached();
        expect($main->locator('[data-phem-toast][data-type="loading"]'))->toHaveCount(1);
        expect($main->locator('[data-phem-toast][data-type="loading"]'))->toHaveCount(0, ['timeout' => 10000]);
        $promise = $main->locator('[data-phem-toast-id="promise-1"]');
        expect($promise)->toHaveCount(1);
        self::assertContains($promise->locator('[data-phem-toast-description]')->textContent(), ['Success: operation completed', 'Error: operation failed']);

        // Custom: the template shows the data of the toast.
        $region('Custom')->getByRole('button', ['name' => 'Create custom toast'])->click();
        expect($viewport('custom')->locator('[data-phem-toast]'))->toContainText('data.userId is 123');

        // Deduplicated toast: the same id updates the toast, and counts the updates.
        $save = $region('Deduplicated toast')->getByRole('button', ['name' => 'Save draft']);
        $save->click();
        $draft = $viewport('pulse')->locator('[data-phem-toast]');
        expect($draft)->toHaveCount(1);
        $save->click();
        expect($draft)->toHaveAttribute('data-update-key', '1');
        expect($draft)->toHaveAttribute('data-updated', '');
        expect($draft)->toHaveCount(1);

        // Varying heights: a toast with one of the texts.
        $region('Varying heights')->getByRole('button', ['name' => 'Create varying height toast'])->click();
        expect($main->locator('[data-phem-toast]')->first())->toContainText('created');
    }
}
