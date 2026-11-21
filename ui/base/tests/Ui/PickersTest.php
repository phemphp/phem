<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PickersTest extends KernelTestCase
{
    use InteractsWithPhem;

    private const string APP = 'Phem\Ui\Base\Tests\App\\';

    public function testTheComboboxLinksItsLabelAndHasStableItemIds(): void
    {
        $this->mount(self::APP.'ComboboxDemo')
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-side-offset-value="4"]')
            ->assertSelectorExists('label[for="fruit-input"]')
            ->assertSelectorExists('input#fruit-input[data-phem-combobox-target="input"]')
            ->assertSelectorExists('button[data-phem-combobox-target="clear"][aria-label="Clear selection"]')
            ->assertSelectorExists('button[data-phem-combobox-target="trigger"][aria-label="Open popup"] svg')
            // Tab goes from the input out of the combobox, not to a list that scrolls.
            ->assertSelectorExists('[data-phem-combobox-target="popup"][popover="manual"] [data-phem-combobox-target="list"][tabindex="-1"]')
            ->assertSelectorExists('#fruit-item-apple[data-phem-combobox-target="item"][data-selected]')
            ->assertSelectorExists('#fruit-item-banana:not([data-selected])')
            ->assertSelectorExists('[data-phem-combobox-target="empty"]');
    }

    public function testAComboboxHasOneSelectedItemAndAnAutocompleteHasNone(): void
    {
        $this->mount(self::APP.'ComboboxSelectionDemo')
            ->assertSelectorExists('#pick-input')
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-selection-mode-value="single"][data-phem-combobox-open-on-input-click-value="true"] input[type="hidden"][name="fruit"][data-phem-combobox-target="value"]')
            // The first selection: the value of the form, the text of the input and the selected item.
            ->assertSelectorExists('input[type="hidden"][name="fruit"][value="apple"]')
            ->assertSelectorExists('#pick-input[value="Apple"]')
            ->assertSelectorExists('#pick-item-apple[data-selected]')
            ->assertSelectorExists('#pick-item-pear:not([data-selected])')
            // The props set the selection mode and the click on the input.
            ->assertSelectorCount(1, '[data-phem-combobox-selection-mode-value]')
            ->assertSelectorCount(1, '[data-phem-combobox-open-on-input-click-value]')
            ->assertSelectorNotExists('input[name="tag"]');

        // A list of the server: no selection, and no open on a click.
        $this->mount(self::APP.'ComboboxDemo')
            ->assertSelectorExists('[data-phem-combobox-target="inputGroup"] button[data-phem-combobox-target="clear"]')
            ->assertSelectorNotExists('[data-phem-combobox-selection-mode-value]')
            ->assertSelectorNotExists('[data-phem-combobox-open-on-input-click-value]');
    }

    public function testTheInputHasItsOwnBorderAndTheChipsAreTheBox(): void
    {
        // An input out of a group (here an Autocomplete) has its own border.
        $this->mount(self::APP.'ComboboxSelectionDemo')
            ->assertSelectorExists('#tags-input[class~="border"][class~="w-64"]');

        // In a group, the input keeps its border and fills the group. The buttons go over its end.
        $this->mount(self::APP.'ComboboxDemo')
            ->assertSelectorExists('[data-phem-combobox-target="inputGroup"][class~="relative"][class~="[&>input]:w-full"]:not([class~="border"])')
            ->assertSelectorExists('[data-phem-combobox-target="inputGroup"] > input#fruit-input[class~="border"]');

        // With `multiple`, the chips are the box, and the input in them has no border.
        $this->mount(self::APP.'ComboboxMultipleDemo')
            ->assertSelectorExists('[data-phem-combobox-target="chips"][class~="combobox-chips"][class~="border"][class~="[&>input]:border-0"]')
            ->assertSelectorExists('[data-phem-combobox-target="chips"] > input#langs-input');
    }

    public function testAListOfTheServerWithABoundValueSelectsOneItem(): void
    {
        $this->mount(self::APP.'ComboboxServerSelectionDemo')
            // `phem:bind` on the root, with no `multiple`, gives the `single` selection mode.
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-selection-mode-value="single"][data-phem-combobox-open-on-input-click-value="true"]')
            // A hidden input binds the selected value, with no name.
            ->assertSelectorExists('input[type="hidden"][data-phem-combobox-target="value"][data-phem-bind]:not([name])')
            ->assertSelectorExists('input[type="hidden"][data-phem-combobox-target="value"][value="pear"]')
            // The item with the bound value is selected.
            ->assertSelectorExists('#server-item-pear[data-selected][data-label="Pear"]')
            ->assertSelectorExists('#server-item-apple:not([data-selected])');
    }

    public function testTheStatusOfASearchHasAPartForEachState(): void
    {
        $this->mount(self::APP.'ComboboxServerSelectionDemo')
            // The browser shows the part of the state: all the parts are hidden at first.
            ->assertSelectorExists('[data-phem-combobox-target="status"] [data-phem-combobox-status="pending"][hidden] .animate-spin[aria-hidden="true"]')
            ->assertSelectorTextContains('[data-phem-combobox-target="status"] [data-phem-combobox-status="pending"]', 'Searching…')
            ->assertSelectorTextContains('[data-phem-combobox-status="idle"][hidden]', 'Start typing.')
            ->assertSelectorTextContains('[data-phem-combobox-status="empty"][hidden]', 'No matches for "Pear".')
            ->assertSelectorTextContains('[data-phem-combobox-status="error"][hidden]', 'Failed.');
    }

    public function testTheStatusHasTheResultsAndTheMoreTemplateAndTheEmptyPartHasATextTemplate(): void
    {
        $this->mount(self::APP.'ComboboxStatusTextsDemo')
            ->assertSelectorExists('[data-phem-combobox-limit-value="1"]')
            ->assertSelectorTextContains('[data-phem-combobox-target="status"] [data-phem-combobox-status="results"][hidden]', '2 results found')
            // The browser fills the templates at each render.
            ->assertSelectorExists('[data-phem-combobox-target="status"] [data-phem-combobox-status="more"][hidden] > span[data-phem-combobox-text="Hiding {count} results"]:empty')
            ->assertSelectorExists('[data-phem-combobox-target="empty"] > span[data-phem-combobox-text=\'No results found for "{query}"\']:empty')
            ->assertSelectorNotExists('[data-phem-combobox-status="pending"]');
    }

    public function testAFuzzyFilterHasTheKeywordsOfAnItemAndAHighlightPart(): void
    {
        $this->mount(self::APP.'ComboboxFuzzyDemo')
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-filter-value="fuzzy"]')
            // One keyword on each line: the browser tests each one alone.
            ->assertSelectorExists('#fuzzy-item-lemon[data-keywords="citrus\\A sour"]')
            ->assertSelectorNotExists('#fuzzy-item-apple[data-keywords]')
            ->assertSelectorExists('#fuzzy-item-lemon > span[data-phem-combobox-target="highlight"][data-phem-combobox-mark-class="bg-transparent font-bold text-blue-800 dark:text-blue-500"]')
            ->assertSelectorTextContains('#fuzzy-item-lemon > span[data-phem-combobox-target="highlight"]', 'Lemon')
            ->assertSelectorExists('#fuzzy-item-apple > span[data-phem-combobox-target="highlight"][data-phem-combobox-mark-class="font-bold"]');
    }

    public function testAListOfTheServerWithMultipleOpensOnAClickOnTheInput(): void
    {
        $this->mount(self::APP.'ComboboxServerMultipleDemo')
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-multiple-value="true"][data-phem-combobox-open-on-input-click-value="true"]:not([data-phem-combobox-selection-mode-value])')
            ->assertSelectorExists('#team-item-a[data-selected][aria-selected="true"]');
    }

    public function testTheChipTemplateGivesTheChipOfAChoiceBeforeTheAnswer(): void
    {
        $html = $this->mount(self::APP.'ComboboxServerMultipleDemo')
            ->assertSelectorExists('[data-phem-combobox-target="chips"] > template[data-phem-combobox-target="chipTemplate"]')
            ->html();

        // The chip of the template has the placeholders of the label, and its remove button has
        // the X, a hidden spinner and a hidden warning: the variants of the chip show them.
        $template = (string) preg_replace('/^.*<template data-phem-combobox-target="chipTemplate">(.*?)<\/template>.*$/s', '$1', $html);
        self::assertStringContainsString('aria-label="{label}"', $template);
        self::assertStringContainsString('aria-label="Remove {label}"', $template);
        self::assertStringContainsString('group-data-pending:hidden group-data-failed:hidden', $template);
        self::assertStringContainsString('animate-spin', $template);
        self::assertStringContainsString('group-data-pending:inline-block', $template);
        self::assertStringContainsString('group-data-failed:block', $template);
    }

    public function testTheHighlightPropsAndAGrid(): void
    {
        $this->mount(self::APP.'ComboboxHighlightDemo')
            ->assertSelectorExists('[data-phem-combobox-auto-highlight-value="always"][data-phem-combobox-keep-highlight-value="true"][data-phem-combobox-highlight-item-on-hover-value="false"]:not([data-phem-combobox-grid-value])')
            ->assertSelectorExists('[data-phem-combobox-auto-highlight-value="true"][data-phem-combobox-grid-value="true"]:not([data-phem-combobox-keep-highlight-value]):not([data-phem-combobox-highlight-item-on-hover-value])')
            ->assertSelectorExists('#palette-input ~ [data-phem-combobox-target="list"]')
            ->assertSelectorExists('[role="row"][data-phem-combobox-target="row"] #typed-item-a');
    }

    public function testAComboboxInsertsTheChoiceIntoAField(): void
    {
        $this->mount(self::APP.'ComboboxInsertIntoDemo')
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-insert-into-value="message"][data-phem-combobox-grid-value="true"][data-phem-combobox-filter-value="contains"]')
            ->assertSelectorExists('[data-phem-combobox-target="row"] #emoji-item-😀[data-value="😀"][data-label="grinning face"]');
    }

    public function testAMultipleComboboxWithAListOfTheBrowserKeepsTheChosenItems(): void
    {
        $this->mount(self::APP.'ComboboxMultipleDemo')
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-selection-mode-value="multiple"][data-phem-combobox-multiple-value="true"]')
            ->assertSelectorExists('[data-phem-combobox-target="chips"][aria-label="Selected languages"]')
            ->assertSelectorExists('#langs-input[placeholder="e.g. Go"][data-phem-combobox-description="{count} selected"]')
            ->assertSelectorCount(3, '[data-phem-combobox-target="chip"][data-value]')
            ->assertSelectorExists('[data-phem-combobox-target="chip"][data-value="go"]:not([hidden])')
            ->assertSelectorExists('[data-phem-combobox-target="chip"][data-value="js"][hidden]')
            ->assertSelectorExists('[data-phem-combobox-target="chip"][data-value="php"]:not([hidden])')
            ->assertSelectorExists('#langs-item-go[data-selected][aria-selected="true"]')
            ->assertSelectorExists('#langs-item-js:not([data-selected])[aria-selected="false"]')
            ->assertSelectorExists('#langs-item-php[data-selected]');
    }

    public function testASelectionModeGivesTheSelectionOfAListOfTheServerToTheBrowser(): void
    {
        $html = $this->mount(self::APP.'ComboboxFormDemo')
            // A `name` alone does not change the selection of a list of the server.
            ->assertSelectorExists('[data-controller="phem-combobox"]:not([data-phem-combobox-selection-mode-value]) #tag-input')
            ->assertSelectorNotExists('input[name="tag"]')
            // One value: the hidden input of the root.
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-selection-mode-value="single"] input[type="hidden"][name="owner"][data-phem-combobox-target="value"]')
            // More than one value: a hidden input in each chip. The input of a hidden chip is disabled.
            ->assertSelectorExists('[data-controller="phem-combobox"][data-phem-combobox-selection-mode-value="multiple"][data-phem-combobox-multiple-value="true"]')
            ->assertSelectorExists('[data-phem-combobox-target="chip"][data-value="ada"]:not([hidden]) input[type="hidden"][name="reviewers"][value="ada"]:not([disabled])')
            ->assertSelectorExists('[data-phem-combobox-target="chip"][data-value="bob"][hidden] input[type="hidden"][name="reviewers"][value="bob"][disabled]')
            ->assertSelectorNotExists('input[type="hidden"][name="reviewers"][data-phem-combobox-target="value"]')
            // A chip with no value out of the template has no input.
            ->assertSelectorCount(2, '[data-phem-combobox-target="chips"] > [data-phem-combobox-target="chip"] input[name="reviewers"]')
            ->html();

        // The chip of the template gets the value of the item.
        $template = (string) preg_replace('/^.*<template data-phem-combobox-target="chipTemplate">(.*?)<\/template>.*$/s', '$1', $html);
        self::assertStringContainsString('name="reviewers" value="{value}"', $template);
    }

    public function testThePopupOfAnAutocompleteHasNoAnimation(): void
    {
        $this->mount(self::APP.'ComboboxSelectionDemo')
            ->assertSelectorCount(3, '[data-phem-combobox-target="popup"]')
            ->assertSelectorCount(2, '[data-phem-combobox-target="popup"][class*="transition-"][class*="data-starting-style:"]')
            ->assertSelectorCount(1, '[data-phem-combobox-target="popup"]:not([class*="transition-"]):not([class*="data-starting-style:"]):not([class*="data-ending-style:"])');
    }

    public function testTheDrawerHasItsParts(): void
    {
        $this->mount(self::APP.'DrawerPartsDemo')
            ->assertSelectorExists('[data-controller="phem-drawer-provider"] > [data-phem-drawer-provider-target="background"] + [data-phem-drawer-provider-target="indent"]')
            ->assertSelectorExists('button[data-phem-dialog-for="settings-drawer"][data-phem-dialog-payload="Settings"]:not([data-phem-dialog-target])')
            ->assertSelectorExists('#settings-drawer[data-controller="phem-dialog"][data-phem-dialog-swipe-direction-value="left"][data-phem-dialog-modal-value="false"][data-phem-dialog-disable-pointer-dismissal-value="true"][data-phem-dialog-virtual-keyboard-value="true"][data-phem-dialog-confirm-close-value="discard"][data-phem-dialog-final-focus-value="#done"]')
            ->assertSelectorExists('#settings-drawer > div[data-phem-dialog-target="swipeArea"]')
            ->assertSelectorExists('#settings-drawer > div[data-phem-dialog-target="backdrop"][hidden]')
            ->assertSelectorExists('#settings-drawer > dialog[data-phem-dialog-target="viewport"][class~="justify-start"] > div[data-phem-dialog-target="popup"][class~="-ms-12"] h2 span[data-phem-dialog-target="payload"]');
    }

    public function testTheDrawerIsADialogWithASwipe(): void
    {
        $html = $this->mount(self::APP.'DrawerDemo')
            ->assertSelectorExists('[data-controller="phem-dialog"][data-phem-dialog-swipe-direction-value="right"]')
            ->assertSelectorExists('dialog[data-phem-dialog-target="popup"] h2[data-phem-dialog-target="title"]')
            ->assertSelectorExists('dialog [data-phem-dialog-target="content"]')
            ->html();

        self::assertStringContainsString('border-l', $html);
        self::assertStringContainsString('--drawer-swipe-movement-x', $html);
        self::assertMatchesRegularExpression('/<h2[^>]*class="[^"]*mb-1/', $html);
    }

    public function testThePreviewCardOpensOnHover(): void
    {
        $this->mount(self::APP.'PreviewCardDemo')
            ->assertSelectorExists('[data-controller="phem-popover"][data-phem-popover-open-on-hover-value="true"][data-phem-popover-delay-value="600"][data-phem-popover-close-delay-value="300"]')
            ->assertSelectorExists('a[href="https://en.wikipedia.org/wiki/Typography"][data-phem-popover-target="trigger"]')
            // No whitespace around the text of the link: the underline would include it.
            ->assertSeeHtml('>typography</a>')
            ->assertSelectorExists('[data-phem-popover-target="popup"][hidden]');
    }

    public function testTheToastViewportHasATemplate(): void
    {
        $html = $this->mount(self::APP.'ToastDemo')
            ->assertSelectorExists('[data-controller="phem-toast"][data-phem-toast-close-label-value="Dismiss"] template[data-phem-toast-target="template"]')
            ->html();

        self::assertStringContainsString('data-phem-toast-content', $html);
        self::assertStringContainsString('data-phem-toast-title', $html);
        self::assertStringContainsString('data-phem-toast-description', $html);
        self::assertStringContainsString('data-phem-toast-close', $html);
        self::assertStringContainsString('data-phem-toast-action', $html);
    }

    public function testTheToastViewportHasAPositionAnAnchorAndATemplateOfTheApp(): void
    {
        $html = $this->mount(self::APP.'ToastOptionsDemo')
            ->assertSelectorExists('[data-phem-toast-name-value="top"][data-phem-toast-swipe-direction-value="up"][class~="top-[1rem]"]')
            ->assertSelectorExists('[data-phem-toast-name-value="anchored"][data-phem-toast-side-value="top"][data-phem-toast-side-offset-value="10"][class~="outline-0"]')
            ->assertSelectorExists('[data-phem-toast-name-value="custom"] template')
            ->html();

        // The template of the app replaces the default parts: it has no close button.
        $custom = substr($html, (int) strpos($html, 'data-phem-toast-name-value="custom"'));
        $custom = substr($custom, 0, (int) strpos($custom, '</template>'));
        self::assertStringContainsString('data-phem-toast-data="userId"', $custom);
        self::assertDoesNotMatchRegularExpression('/data-phem-toast-close(?!-label)/', $custom);

        // The anchored template has no arrow, as the Base UI demo.
        $anchored = substr($html, (int) strpos($html, 'data-phem-toast-name-value="anchored"'));
        self::assertStringNotContainsString('data-phem-toast-arrow', substr($anchored, 0, (int) strpos($anchored, '</template>')));
    }
}
