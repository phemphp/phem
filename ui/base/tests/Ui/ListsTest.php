<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListsTest extends KernelTestCase
{
    use InteractsWithPhem;

    private const string APP = 'Phem\Ui\Base\Tests\App\\';

    public function testTheScrollAreaHasItsParts(): void
    {
        $this->mount(self::APP.'ScrollAreaDemo')
            ->assertSelectorExists('[data-controller="phem-scroll-area"] [data-phem-scroll-area-target="viewport"] [data-phem-scroll-area-target="content"]')
            // The controller gives the viewport its `tabindex`, only when it scrolls.
            ->assertSelectorExists('[data-phem-scroll-area-target="viewport"]:not([tabindex])')
            ->assertSelectorExists('[data-phem-scroll-area-target="scrollbar"][data-orientation="vertical"] [data-phem-scroll-area-target="thumb"]');
    }

    public function testASelectWithNoValueShowsItsPlaceholder(): void
    {
        $this->mount(self::APP.'SelectDemo')
            ->assertSelectorExists('[data-controller="phem-select"]:not([data-phem-select-value-value])')
            // No loop by default, as in Base UI.
            ->assertSelectorExists('[data-controller="phem-select"][data-phem-select-loop-focus-value="false"]')
            ->assertSelectorExists('[data-phem-select-target="icon"][aria-hidden="true"]')
            ->assertSelectorExists('[data-phem-select-target="itemIndicator"][aria-hidden="true"]')
            ->assertSelectorExists('#apple-label[data-phem-select-target="label"]')
            ->assertSelectorExists('button[data-phem-select-target="trigger"][aria-labelledby="apple-label"]')
            ->assertSelectorExists('[data-phem-select-target="value"][data-placeholder][placeholder="Select apple"]')
            ->assertSelectorTextContains('[data-phem-select-target="value"]', 'Select apple')
            ->assertSelectorExists('input[name="apple"][data-phem-select-target="input"]')
            ->assertSelectorExists('[data-phem-select-target="popup"][popover="manual"] [data-phem-select-target="item"][data-value="gala"]')
            ->assertSelectorExists('[data-phem-select-target="value"][data-phem-select-template="{first} (+{others} more)"][data-phem-select-markup]');
    }

    public function testASelectWithAValueMarksItsItem(): void
    {
        $this->mount(self::APP.'SelectDemo', ['value' => 'fuji'])
            ->assertSelectorExists('[data-phem-select-value-value="fuji"]')
            ->assertSelectorExists('[data-phem-select-target="value"]:not([data-placeholder])')
            ->assertSelectorExists('[data-value="fuji"][data-selected]');
    }

    public function testTheMenubarTriggersHaveNoCaret(): void
    {
        $this->mount(self::APP.'MenubarDemo')
            ->assertSelectorExists('[data-controller="phem-menubar"][role="menubar"] [data-controller="phem-menu"] button[data-phem-menu-target="trigger"]')
            ->assertSelectorNotExists('button[data-phem-menu-target="trigger"] svg');
    }

    public function testOnlyAMenuOfAMenubarStopsTheAnimationOfAnInstantOpen(): void
    {
        $this->mount(self::APP.'MenubarDemo')
            ->assertSelectorCount(2, '[data-phem-menu-target="popup"]')
            ->assertSelectorCount(2, '[data-phem-menu-target="popup"][class~="data-instant:transition-none"]');
    }

    public function testTheNavigationMenuHasItsParts(): void
    {
        $this->mount(self::APP.'NavigationMenuDemo')
            ->assertSelectorExists('nav[data-phem-navigation-menu-collision-padding-inline-value="20"][data-phem-navigation-menu-flip-value="false"]:not([data-phem-navigation-menu-collision-padding-value])')
            ->assertSelectorExists('nav[data-controller="phem-navigation-menu"] ul[data-phem-navigation-menu-target="list"] li[data-value="overview"] button[data-phem-navigation-menu-target="trigger"] [data-phem-navigation-menu-target="icon"]')
            ->assertSelectorExists('[data-phem-navigation-menu-target="content"][hidden] a[data-phem-navigation-menu-target="link"]')
            ->assertSelectorExists('[data-phem-navigation-menu-target="positioner"][hidden] [data-phem-navigation-menu-target="popup"] [data-phem-navigation-menu-target="viewport"]');
    }
}
