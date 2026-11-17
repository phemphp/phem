<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests\Ui;

use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ControlsTest extends KernelTestCase
{
    use InteractsWithPhem;

    private const string APP = 'Phem\Ui\Base\Tests\App\\';

    public function testAPressedToggle(): void
    {
        $this->mount(self::APP.'ToggleDemo')
            ->assertSelectorExists('button[data-controller="phem-toggle"][data-phem-toggle-checked-value="true"][aria-pressed="true"][data-pressed]');
    }

    public function testACheckedSwitch(): void
    {
        $this->mount(self::APP.'SwitchDemo')
            ->assertSelectorExists('[role="switch"][data-controller="phem-toggle"][aria-checked="true"][data-checked][data-phem-toggle-name-value="alerts"]')
            ->assertSelectorExists('[role="switch"] [data-phem-toggle-target="indicator"][data-checked]')
            ->assertSelectorExists('[role="switch"] input[type="checkbox"][hidden][checked][data-phem-toggle-target="input"]');
    }

    public function testABoundSwitchTakesTheStateOfTheApp(): void
    {
        $this->mount(self::APP.'BoundSwitchDemo')
            ->assertSelectorExists('[role="switch"][aria-checked="true"]')
            ->assertSelectorExists('[role="switch"] input[type="checkbox"][data-phem-bind][checked]');
    }

    public function testAnUncheckedCheckboxHidesItsIndicator(): void
    {
        $this->mount(self::APP.'CheckboxDemo')
            ->assertSelectorExists('[role="checkbox"][aria-checked="false"][data-unchecked]')
            ->assertSelectorExists('[data-phem-toggle-target="indicator"][hidden]');
    }

    public function testTheTriggersInAToolbarAreItemsOfTheToolbar(): void
    {
        $this->mount(self::APP.'ToolbarTriggersDemo')
            ->assertSelectorExists('button[data-phem-menu-target="trigger"][data-phem-toolbar-target="item"]')
            ->assertSelectorExists('button[data-phem-popover-target="trigger"][data-phem-toolbar-target="item"]')
            ->assertSelectorExists('button[data-phem-dialog-target="trigger"][data-phem-toolbar-target="item"]')
            ->assertSelectorExists('button[data-phem-tooltip-target="trigger"][data-phem-toolbar-target="item"]')
            ->assertSelectorExists('input[data-phem-number-field-target="input"][data-phem-toolbar-target="item"]');
    }

    public function testASwitchCanBeANativeButtonWithAnUncheckedValue(): void
    {
        $this->mount(self::APP.'NativeButtonSwitchDemo')
            ->assertSelectorExists('button#wifi[type="button"][role="switch"][aria-checked="false"]:not([tabindex])')
            ->assertSelectorExists('button#wifi[data-phem-toggle-unchecked-value-value="off"][data-phem-toggle-form-value="settings"]')
            ->assertSelectorExists('button#wifi input[type="hidden"][data-phem-toggle-target="input"]');
    }

    public function testARadioCanBeANativeButton(): void
    {
        $this->mount(self::APP.'NativeButtonRadioDemo')
            ->assertSelectorExists('button#disk-ssd[type="button"][role="radio"][aria-checked="true"]');
    }

    public function testACheckboxCanBeANativeButton(): void
    {
        $this->mount(self::APP.'NativeButtonCheckboxDemo')
            ->assertSelectorExists('button#news[type="button"][role="checkbox"][aria-checked="true"]:not([tabindex])')
            ->assertSelectorExists('button#news input[type="hidden"][data-phem-toggle-target="input"]')
            ->assertSelectorNotExists('button#news input[type="checkbox"]');
    }

    public function testTheCheckboxGroupOwnsTheStateOfItsCheckboxes(): void
    {
        $this->mount(self::APP.'CheckboxGroupDemo')
            ->assertSelectorExists('[data-controller="phem-checkbox-group"][data-phem-checkbox-group-value-value=\'["fuji"]\'][data-phem-checkbox-group-all-values-value=\'["fuji","gala"]\']')
            ->assertSelectorExists('[data-phem-checkbox-group-target="parent"][aria-checked="mixed"][data-indeterminate]')
            ->assertSelectorExists('[data-phem-checkbox-group-target="item"][data-value="fuji"][aria-checked="true"]:not([data-phem-toggle-checked-value])')
            ->assertSelectorExists('[data-phem-checkbox-group-target="item"][data-value="fuji"] input[checked]')
            ->assertSelectorExists('[data-phem-checkbox-group-target="item"][data-value="gala"][aria-checked="false"] input:not([checked])');
    }

    public function testTheToggleGroupPressesItsDefaultValue(): void
    {
        $this->mount(self::APP.'ToggleGroupDemo')
            ->assertSelectorExists('[data-controller="phem-toggle-group"][data-phem-toggle-group-value-value=\'["left"]\']')
            ->assertSelectorExists('[data-phem-toggle-group-target="item"][data-value="left"][data-pressed]:not([data-phem-toggle-checked-value])')
            ->assertSelectorExists('[data-phem-toggle-group-target="item"][data-value="right"][aria-pressed="false"]');
    }

    public function testTheToolbarMarksItsItemsAndGroups(): void
    {
        $this->mount(self::APP.'ToolbarDemo')
            ->assertSelectorExists('[data-controller="phem-toolbar"][role="toolbar"]')
            ->assertSelectorExists('[data-controller="phem-toggle-group"][data-phem-toolbar-target="group"] [data-phem-toolbar-target="item"][data-phem-toggle-group-target="item"]')
            ->assertSelectorExists('[data-phem-toolbar-target="separator"]')
            ->assertSelectorExists('[data-phem-toolbar-target="group"] button[data-phem-toolbar-target="item"][aria-disabled="true"]');
    }

    public function testTheRadioGroupChecksItsDefaultValue(): void
    {
        $this->mount(self::APP.'RadioGroupDemo')
            ->assertSelectorExists('[data-controller="phem-radio-group"][data-phem-radio-group-value-value="fuji"]')
            ->assertSelectorExists('[data-phem-radio-group-target="radio"][data-value="fuji"][aria-checked="true"] [data-phem-radio-group-target="indicator"]:not([hidden])')
            ->assertSelectorExists('[data-phem-radio-group-target="radio"][data-value="gala"] [data-phem-radio-group-target="indicator"][hidden]')
            ->assertSelectorExists('input[type="hidden"][name="apple"][data-phem-radio-group-target="input"]');
    }

    public function testTheAvatarHasAnImageAndAFallback(): void
    {
        $this->mount(self::APP.'AvatarDemo')
            ->assertSelectorExists('[data-controller="phem-avatar"]:not([data-phem-avatar-delay-value]) img[data-phem-avatar-target="image"][alt="Ada"]')
            ->assertSelectorExists('[data-phem-avatar-target="fallback"][data-phem-avatar-delay="600"][hidden]')
            ->assertSelectorTextContains('[data-phem-avatar-target="fallback"]', 'AL');
    }
}
