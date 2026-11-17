<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OverlaysTest extends KernelTestCase
{
    use InteractsWithPhem;

    private const string APP = 'Phem\Ui\Base\Tests\App\\';

    public function testAClosedCollapsibleHidesItsPanel(): void
    {
        $this->mount(self::APP.'CollapsibleDemo')
            ->assertSelectorExists('[data-controller="phem-collapsible"][data-closed]')
            ->assertSelectorExists('button[data-phem-collapsible-target="trigger"][aria-expanded="false"]')
            ->assertSelectorExists('[data-phem-collapsible-target="panel"][hidden]');
    }

    public function testACollapsiblePanelCanBeHiddenUntilFound(): void
    {
        $this->mount(self::APP.'CollapsibleHiddenUntilFoundDemo')
            ->assertSelectorExists('[data-phem-collapsible-target="panel"][data-phem-collapsible-hidden-until-found][hidden="until-found"]');
    }

    public function testAnOpenCollapsibleShowsItsPanel(): void
    {
        $this->mount(self::APP.'CollapsibleDemo', ['open' => true])
            ->assertSelectorExists('[data-phem-collapsible-open-value="true"][data-open]')
            ->assertSelectorExists('button[data-panel-open][aria-expanded="true"]')
            ->assertSelectorExists('[data-phem-collapsible-target="panel"][data-open]:not([hidden])');
    }

    public function testTheAccordionLinksItsTriggersAndPanels(): void
    {
        $this->mount(self::APP.'AccordionDemo')
            ->assertSelectorExists('#faq[data-controller="phem-accordion"][data-phem-accordion-open-value=\'["start"]\']')
            ->assertSelectorExists('[data-value="what"][data-phem-accordion-target="item"]:not([data-open])')
            ->assertSelectorExists('#faq-what-trigger[aria-controls="faq-what-panel"][aria-expanded="false"]')
            ->assertSelectorExists('#faq-what-panel[role="region"][aria-labelledby="faq-what-trigger"][hidden="until-found"][data-phem-accordion-hidden-until-found]')
            ->assertSelectorExists('#faq-start-trigger[data-panel-open]')
            ->assertSelectorExists('#faq-start-panel[data-open]:not([hidden])')
            ->assertSelectorExists('#faq[data-orientation="vertical"] [data-phem-accordion-target="item"][data-orientation="vertical"] h3[data-orientation="vertical"] #faq-what-trigger[data-orientation="vertical"]')
            ->assertSelectorExists('#faq-what-panel[data-orientation="vertical"]');
    }

    public function testTheDialogIsANativeDialog(): void
    {
        $this->mount(self::APP.'DialogDemo')
            ->assertSelectorExists('[data-controller="phem-dialog"] button[data-phem-dialog-target="trigger"]')
            ->assertSelectorExists('dialog[data-phem-dialog-target="popup"] h2[data-phem-dialog-target="title"]')
            ->assertSelectorExists('dialog p[data-phem-dialog-target="description"]')
            ->assertSelectorExists('dialog button[data-phem-dialog-target="close"]');
    }

    public function testTheAlertDialogHasTheAlertDialogRole(): void
    {
        $html = $this->mount(self::APP.'AlertDialogDemo')
            ->assertSelectorExists('[data-phem-dialog-disable-pointer-dismissal-value="true"] dialog[role="alertdialog"][data-phem-dialog-target="popup"]')
            ->html();

        self::assertMatchesRegularExpression('/data-phem-dialog-target="trigger"[^>]*class="[^"]*text-red-700"|class="[^"]*text-red-700"[^>]*data-phem-dialog-target="trigger"/', $html);
    }

    public function testThePopoverHasItsPositionValues(): void
    {
        $this->mount(self::APP.'PopoverDemo')
            ->assertSelectorExists('[data-controller="phem-popover"][data-phem-popover-side-value="bottom"][data-phem-popover-align-value="center"][data-phem-popover-side-offset-value="8"]')
            ->assertSelectorExists('[data-phem-popover-target="popup"][popover="manual"][hidden] [data-phem-popover-target="arrow"][aria-hidden="true"][class~="absolute"]')
            ->assertSelectorExists('[data-phem-popover-target="popup"] h2[data-phem-popover-target="title"]');
    }

    public function testTheTooltipIsOnTop(): void
    {
        $this->mount(self::APP.'TooltipDemo')
            ->assertSelectorExists('[data-controller="phem-tooltip"][data-phem-tooltip-side-value="top"][data-phem-tooltip-side-offset-value="11"]')
            ->assertSelectorExists('button[data-phem-tooltip-target="trigger"][aria-label="Bold"]')
            ->assertSelectorExists('[data-phem-tooltip-target="popup"][hidden]');
    }

    public function testTheTabsSelectTheirDefaultValue(): void
    {
        $this->mount(self::APP.'TabsDemo')
            ->assertSelectorExists('[data-controller="phem-tabs"][data-phem-tabs-active-value="overview"][data-orientation="horizontal"]')
            ->assertSelectorExists('[data-value="overview"][data-phem-tabs-target="tab"][aria-selected="true"][data-active]')
            ->assertSelectorExists('[data-value="projects"][data-phem-tabs-target="tab"][aria-selected="false"]')
            ->assertSelectorExists('[data-value="overview"][data-phem-tabs-target="panel"]:not([hidden])')
            ->assertSelectorExists('[data-value="projects"][data-phem-tabs-target="panel"][hidden][data-hidden]')
            ->assertSelectorExists('[data-phem-tabs-target="list"] [data-phem-tabs-target="indicator"]')
            ->assertSelectorExists('a[role="tab"][href="/projects"][data-value="projects"]:not([type])');
    }

    public function testTheMenuPartsHaveTheTargetsOfTheirMenu(): void
    {
        $this->mount(self::APP.'MenuDemo')
            ->assertSelectorExists('[data-controller="phem-menu"][data-phem-menu-align-value="start"] > button[data-phem-menu-target="trigger"]')
            ->assertSelectorExists('[data-phem-menu-target="popup"][popover="manual"] button[data-phem-menu-target="item"]')
            ->assertSelectorExists('[data-phem-menu-target="popup"] [data-controller="phem-menu"][data-phem-menu-open-on-hover-value="true"][data-phem-menu-side-value="inline-end"]')
            ->assertSelectorExists('button[data-phem-menu-target="item"][data-phem-menu-close-on-click="false"]')
            ->assertSelectorExists('button[role="menuitemcheckbox"][aria-checked="true"][data-checked]')
            ->assertSelectorCount(2, 'button[role="menuitemcheckbox"][aria-checked="true"]')
            ->assertSelectorExists('[data-phem-menu-target="group"] button[role="menuitemradio"][data-value="date"][aria-checked="false"][data-unchecked]')
            ->assertSelectorExists('[data-phem-menu-target="group"] button[role="menuitemradio"][data-value="name"][aria-checked="true"][data-checked]')
            // A disabled item keeps the focus of the keyboard, as in Base UI: no `disabled` attribute.
            ->assertSelectorExists('#item-off[aria-disabled="true"][data-disabled]:not([disabled])')
            ->assertSelectorExists('#check-off[aria-disabled="true"][data-disabled]:not([disabled])')
            ->assertSelectorExists('[data-phem-menu-target="separator"][role="separator"][aria-orientation="horizontal"][data-orientation="horizontal"]')
            // Only a menu of a Menubar stops the animation of an instant open.
            ->assertSelectorNotExists('[data-phem-menu-target="popup"][class*="data-instant"]');
    }

    public function testAMenuIsModalUnlessThePointerOpensIt(): void
    {
        $this->mount(self::APP.'MenuOptionsDemo')
            ->assertSelectorExists('#modal[data-phem-menu-modal-value="true"]')
            ->assertSelectorExists('#hover[data-phem-menu-open-on-hover-value="true"]:not([data-phem-menu-modal-value])')
            // `disabled` on the root disables the trigger, and the trigger of a disabled submenu.
            ->assertSelectorExists('#off > button[data-phem-menu-target="trigger"][disabled]')
            ->assertSelectorExists('#off-sub[aria-disabled="true"]');
    }

    public function testTheCheckItemsOfAMenuFollowTheStateRules(): void
    {
        $this->mount(self::APP.'MenuStateDemo')
            // A `checked` prop wins over `defaultChecked`.
            ->assertSelectorExists('#controlled[role="menuitemcheckbox"][aria-checked="false"][data-unchecked]:not([data-checked])')
            // `closeOnClick` removes the attribute that keeps the menu open.
            ->assertSelectorExists('#closes:not([data-phem-menu-close-on-click])')
            ->assertSelectorExists('#controlled[data-phem-menu-close-on-click="false"]')
            // The `value` of the group wins over its `defaultValue` and over the `checked` of an item.
            ->assertSelectorExists('#date[aria-checked="false"]')
            ->assertSelectorExists('#name[aria-checked="true"][data-checked]')
            // The group is not the group of an item of a submenu.
            ->assertSelectorExists('#inner[aria-checked="false"]')
            // With no group, `checked` gives the state.
            ->assertSelectorExists('#alone[role="menuitemradio"][aria-checked="true"]:not([data-value])');
    }

    public function testTheContextMenuPartsHaveTheContextMenuTargets(): void
    {
        $this->mount(self::APP.'ContextMenuDemo')
            ->assertSelectorExists('[data-controller="phem-context-menu"] [data-phem-context-menu-target="trigger"]')
            ->assertSelectorExists('[data-phem-context-menu-target="popup"] [data-phem-context-menu-target="item"]')
            ->assertSelectorNotExists('[data-phem-menu-target]');
    }
}
