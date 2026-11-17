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

    public function testTheHighlightPropsAndAGrid(): void
    {
        $this->mount(self::APP.'ComboboxHighlightDemo')
            ->assertSelectorExists('[data-phem-combobox-auto-highlight-value="always"][data-phem-combobox-keep-highlight-value="true"][data-phem-combobox-highlight-item-on-hover-value="false"]:not([data-phem-combobox-grid-value])')
            ->assertSelectorExists('[data-phem-combobox-auto-highlight-value="true"][data-phem-combobox-grid-value="true"]:not([data-phem-combobox-keep-highlight-value]):not([data-phem-combobox-highlight-item-on-hover-value])')
            ->assertSelectorExists('#palette-input ~ [data-phem-combobox-target="list"]')
            ->assertSelectorExists('[role="row"][data-phem-combobox-target="row"] #typed-item-a');
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
    }
}
