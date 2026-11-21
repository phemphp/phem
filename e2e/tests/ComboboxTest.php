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
 * The `phem-combobox` controller: the server gives the results, and the keyboard chooses one.
 */
final class ComboboxTest extends E2eTestCase
{
    public function testTypeMoveAndChooseAResult(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#input');
        $input->pressSequentially('pa');
        expect($page->locator('#list li'))->toHaveCount(2);
        expect($page->locator('#popup'))->toBeVisible();
        expect($input)->toHaveAttribute('aria-expanded', 'true');

        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveAttribute('aria-activedescendant', 'city-Parma');
        expect($input)->toBeFocused();

        $page->keyboard()->press('Enter');
        expect($page->locator('#city'))->toHaveExactText('Parma');
        expect($input)->toHaveValue('Parma');
        expect($page->locator('#popup'))->toBeHidden();
    }

    public function testNoResultShowsTheEmptyTarget(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $page->locator('#input')->pressSequentially('zz');
        expect($page->locator('#empty'))->toBeVisible();
        expect($page->locator('#popup'))->toHaveAttribute('data-empty', '');

        $page->keyboard()->press('Escape');
        expect($page->locator('#popup'))->toBeHidden();
    }

    public function testTheBrowserFiltersAListOfTheServer(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#fruit-input');
        $input->pressSequentially('ecl');
        expect($page->locator('#fruit-list li:visible'))->toHaveCount(1);
        expect($page->locator('#fruit-4'))->toBeVisible();

        $input->fill('');
        $input->pressSequentially('an');
        expect($page->locator('#fruit-list li:visible'))->toHaveCount(1);
        expect($page->locator('#fruit-2'))->toBeVisible();

        $input->fill('');
        $input->pressSequentially('zz');
        expect($page->locator('#fruit-empty'))->toBeVisible();
        expect($page->locator('#fruit-popup'))->toHaveAttribute('data-empty', '');
    }

    public function testAFilteredGridFillsItsRowsAndAChoiceGoesIntoTheField(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $message = $page->locator('#message');
        $message->fill('Hi !');
        $page->evaluate("document.getElementById('message').setSelectionRange(3, 3)");

        $page->locator('#emoji-trigger')->click();
        $search = $page->locator('#emoji-input');
        expect($search)->toBeFocused();
        $search->pressSequentially('sm');

        // The two matches go into the first row, and the empty rows and groups hide.
        expect($page->locator('#emoji-smileys-1 [data-phem-combobox-target="item"]:visible'))->toHaveCount(2);
        expect($page->locator('#emoji-smileys-2'))->toBeHidden();
        expect($page->locator('#emoji-animals'))->toBeHidden();

        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowRight');
        expect($page->getByLabel('smiling face with sunglasses'))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');

        // The emoji goes in at the caret, and the field gets the focus with the caret after it.
        expect($message)->toHaveValue('Hi 😎!');
        expect($message)->toBeFocused();
        self::assertSame([5, 5], $page->evaluate("[document.getElementById('message').selectionStart, document.getElementById('message').selectionEnd]"));
        expect($page->locator('#emoji-popup'))->toBeHidden();
        expect($search)->toHaveValue('');

        // The next open shows all the emojis in their first rows.
        $page->locator('#emoji-trigger')->click();
        expect($page->locator('#emoji-smileys-1 [data-phem-combobox-target="item"]:visible'))->toHaveCount(3);
        expect($page->locator('#emoji-smileys-2'))->toBeVisible();
        expect($page->locator('#emoji-animals'))->toBeVisible();
    }

    public function testTheBothModeCompletesTheInputAndChoosesAnItem(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#fruit-input');
        $input->pressSequentially('e');
        expect($input)->toHaveAttribute('aria-autocomplete', 'both');

        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveValue('Apple');
        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveValue('Cherry');

        $page->keyboard()->press('Escape');
        expect($input)->toHaveValue('e');
        expect($page->locator('#fruit-popup'))->toBeHidden();

        // The first key opens the popup with no highlight.
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($input)->toHaveValue('Cherry');
        expect($page->locator('#fruit-popup'))->toBeHidden();

        // An event of the other field morphs the page: the text stays.
        $page->locator('#input')->pressSequentially('pa');
        expect($page->locator('#list li'))->toHaveCount(2);
        expect($input)->toHaveValue('Cherry');
    }

    public function testAListAndItemsWithNoIdLinkWithElementReferences(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->getByRole('combobox', ['name' => 'Color']);
        $input->pressSequentially('re');
        expect($page->locator('#color-popup'))->toBeVisible();
        $controls = "document.getElementById('color-input').ariaControlsElements?.[0]?.classList.contains('color-list') ?? false";
        self::assertTrue($page->evaluate($controls));

        $page->keyboard()->press('ArrowDown');
        $active = "document.getElementById('color-input').ariaActiveDescendantElement?.textContent.trim() ?? null";
        self::assertSame('Green', $page->evaluate($active));
        $page->keyboard()->press('ArrowDown');
        self::assertSame('Red', $page->evaluate($active));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.color-list[id], .color-list li[id]').length"));

        $page->keyboard()->press('Escape');
        self::assertNull($page->evaluate($active));

        // The input points to the list only while the popup is open.
        self::assertFalse($page->evaluate($controls));

        // The morph of an event keeps the list with no id, and the link of the open popup.
        $page->locator('#input')->pressSequentially('ly');
        expect($page->locator('#list li'))->toHaveCount(1);
        $input->focus();
        $page->keyboard()->press('ArrowDown');
        self::assertTrue($page->evaluate($controls));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.color-list[id], .color-list li[id]').length"));
    }

    public function testTheSingleSelectionModeSelectsOneItemWithNoFreeText(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#picker-input');
        expect($page->locator('#picker-clear'))->toBeHidden();

        // A click on the input opens the popup with all the items.
        $input->click();
        expect($page->locator('#picker-popup'))->toBeVisible();
        $all = $page->locator('#picker-popup [role="option"]:visible')->count();

        $first = $page->locator('#pick-1');
        $label = (string) $first->textContent();
        $first->click();
        expect($input)->toHaveValue(trim($label));
        expect($first)->toHaveAttribute('data-selected', '');
        expect($page->locator('#picker-value'))->toHaveValue(strtolower(trim($label)));
        expect($page->locator('#picker-clear'))->toBeVisible();

        // The popup opens again with all the items, not only the selected one.
        $input->click();
        expect($page->locator('#picker-popup [role="option"]:visible'))->toHaveCount($all);

        // A text that is not an item goes away when the popup closes.
        $input->fill('zzz');
        $input->blur();
        expect($input)->toHaveValue(trim($label));

        $page->locator('#picker-clear')->click();
        expect($input)->toHaveValue('');
        expect($page->locator('#picker-value'))->toHaveValue('');
        expect($first)->not()->toHaveAttribute('data-selected', '');
    }

    public function testAClearOnAClosedPopupKeepsItClosed(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#picker-input');
        $input->click();
        $page->locator('#pick-1')->click();
        expect($page->locator('#picker-popup'))->toBeHidden();

        $page->locator('#picker-clear')->click();
        expect($input)->toHaveValue('');
        expect($page->locator('#picker-popup'))->toBeHidden();
        expect($input)->toHaveAttribute('aria-expanded', 'false');
    }

    public function testEnterWithNoHighlightedItemClosesThePopup(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#picker-input');
        $input->click();
        $page->locator('#pick-2')->click();
        expect($input)->toHaveValue('Banana');

        // A new text in one step: an empty input would remove the selection, as in Base UI.
        $input->fill('l');
        $input->pressSequentially('e');
        expect($page->locator('#picker-popup'))->toBeVisible();
        self::assertNull($input->getAttribute('aria-activedescendant'));

        $page->keyboard()->press('Enter');
        expect($page->locator('#picker-popup'))->toBeHidden();
        expect($input)->toHaveValue('Banana');
    }

    public function testTheSingleSelectionModeKeepsANewSelectionOfTheKeyboard(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#reviewer-input');
        $input->click();
        $input->fill('lindsay');
        $page->locator('#person-lindsay')->click();
        expect($input)->toHaveValue('Lindsay Walton');
        expect($page->locator('#reviewer-value'))->toHaveValue('lindsay');

        // The user deletes the text until the search gives a second person. The server render
        // still has the old selection (`data-selected` on the item of Lindsay).
        foreach (['Lindsay Walto', 'Lindsay', 'Lin', 'Li'] as $text) {
            $input->fill($text);
            expect($page->locator('#person-lindsay'))->toBeVisible();
        }

        expect($page->locator('#person-leslie'))->toBeVisible();

        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#person-leslie'))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('Enter');
        expect($input)->toHaveValue('Leslie Alexander');

        // The answer of the server has no person: the item of Lindsay leaves the list, and the
        // browser keeps the new selection.
        expect($page->locator('#person-lindsay'))->toHaveCount(0);
        expect($input)->toHaveValue('Leslie Alexander');
        expect($page->locator('#reviewer-value'))->toHaveValue('leslie');
        expect($page->locator('#reviewer-clear'))->toBeVisible();
        expect($page->locator('#person-leslie'))->toHaveAttribute('aria-selected', 'true');
    }

    public function testThePointerOutOfTheItemsRemovesTheHighlight(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->getByRole('combobox', ['name' => 'Color']);
        $input->pressSequentially('re');
        expect($page->locator('#color-popup'))->toBeVisible();

        $page->locator('.color-list li:has-text("Green")')->hover();
        $active = "document.getElementById('color-input').ariaActiveDescendantElement?.textContent.trim() ?? null";
        self::assertSame('Green', $page->evaluate($active));

        $page->mouse()->move(1, 1);
        expect($page->locator('#color-popup'))->toBeVisible();
        expect($page->locator('.color-list li[data-highlighted]'))->toHaveCount(0);
        self::assertNull($page->evaluate($active));
    }

    public function testTheFirstArrowKeyOpensTheAutocompleteWithNoHighlight(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $page->getByRole('combobox', ['name' => 'Color'])->focus();
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#color-popup'))->toBeVisible();
        expect($page->locator('.color-list li[data-highlighted]'))->toHaveCount(0);

        $page->keyboard()->press('ArrowDown');
        expect($page->locator('.color-list li[data-highlighted]'))->toHaveText('Blue');
    }

    public function testAnInlineListInADialogStartsAgainAfterTheCloseAnimation(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $page->locator('#commands-open')->click();
        $input = $page->locator('#palette-input');
        expect($input)->toBeVisible();
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Apple');

        $input->pressSequentially('le');
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#palette-list [data-phem-combobox-target="item"]:visible'))->toHaveCount(2);
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Lemon');

        // While the dialog closes, the palette keeps its text, its filter and its highlight.
        $page->keyboard()->press('Escape');
        expect($page->locator('#commands-dialog'))->toHaveAttribute('data-ending-style', '');
        expect($input)->toHaveValue('le');
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Lemon');

        expect($page->locator('#commands-dialog'))->toBeHidden();
        expect($input)->toHaveValue('');

        $page->locator('#commands-open')->click();
        expect($input)->toBeVisible();
        expect($input)->toHaveValue('');
        expect($page->locator('#palette-list [data-phem-combobox-target="item"]:visible'))->toHaveCount(5);
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Apple');
    }

    public function testAnInlineListInADialogKeepsItsHighlightWhenTheCloseButtonClosesTheDialog(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $page->locator('#commands-open')->click();
        $input = $page->locator('#palette-input');
        $input->pressSequentially('le');
        $page->keyboard()->press('ArrowDown');
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Lemon');

        // The focus goes to the close button: while the dialog closes, the palette does not change.
        $page->locator('#commands-close')->click();
        expect($page->locator('#commands-dialog'))->toHaveAttribute('data-ending-style', '');
        expect($input)->toHaveValue('le');
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Lemon');

        expect($page->locator('#commands-dialog'))->toBeHidden();
        expect($input)->toHaveValue('');

        $page->locator('#commands-open')->click();
        expect($page->locator('#palette-list [data-phem-combobox-target="item"]:visible'))->toHaveCount(5);
        expect($page->locator('#palette-list [data-highlighted]'))->toHaveText('Apple');
    }
}
