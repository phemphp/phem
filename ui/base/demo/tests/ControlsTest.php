<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class ControlsTest extends DemoTestCase
{
    public function testToggle(): void
    {
        $page = $this->visitComponent('toggle');

        $toggle = $page->getByRole('button', ['name' => 'Favorite']);
        expect($toggle)->toHaveAttribute('aria-pressed', 'false');

        $toggle->click();
        expect($toggle)->toHaveAttribute('aria-pressed', 'true');
        expect($toggle)->toHaveAttribute('data-pressed', '');

        $page->keyboard()->press('Space');
        expect($toggle)->toHaveAttribute('aria-pressed', 'false');
    }

    public function testSwitch(): void
    {
        $page = $this->visitComponent('switch');
        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');

        $switch = $hero->getByRole('switch', ['name' => 'Notifications']);
        expect($switch)->toHaveAttribute('aria-checked', 'true');
        expect($switch)->toHaveAttribute('data-checked', '');

        // A click on the text of the label toggles it.
        $hero->getByText('Notifications')->click();
        expect($switch)->toHaveAttribute('aria-checked', 'false');
        expect($switch)->toHaveAttribute('data-unchecked', '');

        $page->keyboard()->press('Space');
        expect($switch)->toHaveAttribute('aria-checked', 'true');
    }

    public function testSwitchExamples(): void
    {
        $page = $this->visitComponent('switch');

        // A native button with a sibling label.
        $native = $page->getByRole('region', ['name' => 'Rendering as a native button'])->locator('[data-demo="preview"]')->getByRole('switch', ['name' => 'Notifications']);
        self::assertSame('BUTTON', $native->evaluate('el => el.tagName'));
        $page->locator('label[for="notifications-switch"]')->click();
        expect($native)->toHaveAttribute('aria-checked', 'true');
        $page->keyboard()->press('Space');
        expect($native)->toHaveAttribute('aria-checked', 'false');

        // In a Field: the form gets the value.
        $field = $page->getByRole('region', ['name' => 'Form integration'])->locator('[data-demo="preview"]')->getByRole('switch', ['name' => 'Notifications']);
        $field->click();
        expect($field)->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('input[name="notifications"]'))->toBeChecked();
    }

    public function testCheckbox(): void
    {
        $page = $this->visitComponent('checkbox');

        $checkbox = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('checkbox', ['name' => 'Enable notifications']);
        expect($checkbox)->toHaveAttribute('aria-checked', 'true');

        $checkbox->click();
        expect($checkbox)->toHaveAttribute('aria-checked', 'false');
        expect($checkbox)->toHaveAttribute('data-unchecked', '');
    }

    public function testCheckboxExamples(): void
    {
        $page = $this->visitComponent('checkbox');

        // An enclosing label: a click on its text checks the checkbox.
        $terms = $page->getByRole('checkbox', ['name' => 'Accept terms and conditions']);
        $page->getByRole('region', ['name' => 'Labeling a checkbox'])->locator('[data-demo="preview"]')->getByText('Accept terms and conditions')->click();
        expect($terms)->toHaveAttribute('aria-checked', 'true');

        // A native button with a sibling label: the label names it, a click on the label and
        // Space check it.
        $native = $page->getByRole('region', ['name' => 'Rendering as a native button'])->locator('[data-demo="preview"]')->getByRole('checkbox', ['name' => 'Enable notifications']);
        self::assertSame('BUTTON', $native->evaluate('el => el.tagName'));
        $page->locator('label[for="notifications-checkbox"]')->click();
        expect($native)->toHaveAttribute('aria-checked', 'true');
        expect($native)->toBeFocused();
        $page->keyboard()->press('Space');
        expect($native)->toHaveAttribute('aria-checked', 'false');

        // In a Field: the label names the checkbox, and the form gets its value.
        $stay = $page->getByRole('checkbox', ['name' => 'Stay logged in for 7 days']);
        $stay->click();
        expect($stay)->toHaveAttribute('aria-checked', 'true');
        expect($page->locator('input[name="stayLoggedIn"]'))->toBeChecked();
    }

    public function testCheckboxGroupKeepsItsValue(): void
    {
        $page = $this->visitComponent('checkbox-group');
        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');

        $fuji = $hero->getByRole('checkbox', ['name' => 'Fuji']);
        $gala = $hero->getByRole('checkbox', ['name' => 'Gala']);

        expect($fuji)->toHaveAttribute('aria-checked', 'true');
        expect($gala)->toHaveAttribute('aria-checked', 'false');

        $gala->click();
        expect($gala)->toHaveAttribute('aria-checked', 'true');
        expect($fuji)->toHaveAttribute('aria-checked', 'true');
        expect($hero->getByRole('group', ['name' => 'Apples']))->toHaveAttribute('data-phem-checkbox-group-value-value', '["fuji-apple","gala-apple"]');
    }

    public function testCheckboxGroupWithNativeButtons(): void
    {
        $page = $this->visitComponent('checkbox-group');
        $example = $page->getByRole('region', ['name' => 'Rendering as a native button'])->locator('[data-demo="preview"]');

        $example->locator('label[for="protocol-https"]')->click();
        expect($example->getByRole('checkbox', ['name' => 'HTTPS']))->toHaveAttribute('aria-checked', 'true');
        expect($example->getByRole('group', ['name' => 'Allowed network protocols']))->toHaveAttribute('data-phem-checkbox-group-value-value', '["https"]');
    }

    public function testCheckboxGroupParentCheckbox(): void
    {
        $page = $this->visitComponent('checkbox-group');
        $example = $page->getByRole('region', ['name' => 'Parent checkbox'])->locator('[data-demo="preview"]');

        $apples = $example->getByRole('checkbox', ['name' => 'Apples']);
        $fuji = $example->getByRole('checkbox', ['name' => 'Fuji']);

        $fuji->click();
        expect($apples)->toHaveAttribute('aria-checked', 'mixed');

        // The parent checks all the items, unchecks all of them, then gives back the mixed state.
        $apples->click();
        expect($apples)->toHaveAttribute('aria-checked', 'true');
        expect($example->getByRole('checkbox', ['name' => 'Granny Smith']))->toHaveAttribute('aria-checked', 'true');
        $apples->click();
        expect($apples)->toHaveAttribute('aria-checked', 'false');
        $apples->click();
        expect($apples)->toHaveAttribute('aria-checked', 'mixed');
        expect($fuji)->toHaveAttribute('aria-checked', 'true');
    }

    public function testCheckboxGroupNestedParentCheckbox(): void
    {
        $page = $this->visitComponent('checkbox-group');
        $example = $page->getByRole('region', ['name' => 'Nested parent checkbox'])->locator('[data-demo="preview"]');

        $all = $example->getByRole('checkbox', ['name' => 'User Permissions']);
        $users = $example->getByRole('checkbox', ['name' => 'Manage Users']);

        // A checkbox of the inner group makes both parents mixed.
        $example->getByRole('checkbox', ['name' => 'Create User'])->click();
        expect($users)->toHaveAttribute('aria-checked', 'mixed');
        expect($all)->toHaveAttribute('aria-checked', 'mixed');

        // The outer parent checks all the checkboxes, also in the inner group.
        $all->click();
        expect($all)->toHaveAttribute('aria-checked', 'true');
        expect($users)->toHaveAttribute('aria-checked', 'true');
        expect($example->getByRole('checkbox', ['name' => 'Assign Roles']))->toHaveAttribute('aria-checked', 'true');

        // An inner checkbox unchecked: the inner parent and the outer parent are mixed.
        $example->getByRole('checkbox', ['name' => 'Delete User'])->click();
        expect($users)->toHaveAttribute('aria-checked', 'mixed');
        expect($all)->toHaveAttribute('aria-checked', 'mixed');
    }

    /**
     * Base UI gives a wrapping `<label>` an id, and links it to the checkbox or the switch with
     * `aria-labelledby`. A `<span role="checkbox">` gets no name from its label without it.
     */
    public function testTheWrappingLabelNamesTheCheckboxAndTheSwitch(): void
    {
        $page = $this->visitComponent('checkbox');
        expect($page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('checkbox', ['name' => 'Enable notifications']))->toHaveCount(1);

        $page = $this->visitComponent('switch');
        expect($page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('switch', ['name' => 'Notifications']))->toHaveCount(1);
    }

    public function testRadioGroup(): void
    {
        $page = $this->visitComponent('radio-group');

        $fuji = $page->getByRole('radio', ['name' => 'Fuji']);
        $gala = $page->getByRole('radio', ['name' => 'Gala']);
        expect($page->getByRole('radiogroup', ['name' => 'Best apple']))->toBeVisible();
        expect($fuji)->toHaveAttribute('aria-checked', 'true');

        $gala->click();
        expect($gala)->toHaveAttribute('aria-checked', 'true');
        expect($fuji)->toHaveAttribute('aria-checked', 'false');

        // The arrow keys check the next radio.
        $page->keyboard()->press('ArrowDown');
        $granny = $page->getByRole('radio', ['name' => 'Granny Smith']);
        expect($granny)->toBeFocused();
        expect($granny)->toHaveAttribute('aria-checked', 'true');
        expect($granny)->toHaveAttribute('data-checked', '');
    }

    public function testRadioGroupExamples(): void
    {
        $page = $this->visitComponent('radio-group');

        // A native button with a sibling label: a click on the label checks it, and the arrow keys
        // move the check.
        $native = $page->getByRole('region', ['name' => 'Rendering as a native button'])->locator('[data-demo="preview"]');
        $hdd = $native->getByRole('radio', ['name' => 'HDD']);
        self::assertSame('BUTTON', $hdd->evaluate('el => el.tagName'));
        $native->locator('label[for="storage-type-hdd"]')->click();
        expect($hdd)->toHaveAttribute('aria-checked', 'true');
        expect($hdd)->toBeFocused();
        $page->keyboard()->press('ArrowUp');
        expect($native->getByRole('radio', ['name' => 'SSD']))->toHaveAttribute('aria-checked', 'true');

        // In a Field and a Fieldset: the form gets the value.
        $form = $page->getByRole('region', ['name' => 'Form integration'])->locator('[data-demo="preview"]');
        $form->getByRole('radio', ['name' => 'HDD'])->click();
        expect($form->locator('input[name="storageType"]'))->toHaveValue('hdd');
    }

    public function testToggleGroupPressesOneItem(): void
    {
        $page = $this->visitComponent('toggle-group');

        $left = $page->getByRole('button', ['name' => 'Align left']);
        $center = $page->getByRole('button', ['name' => 'Align center']);
        expect($left)->toHaveAttribute('aria-pressed', 'true');

        $center->click();
        expect($center)->toHaveAttribute('aria-pressed', 'true');
        expect($left)->toHaveAttribute('aria-pressed', 'false');

        $page->keyboard()->press('ArrowRight');
        expect($page->getByRole('button', ['name' => 'Align right']))->toBeFocused();
    }

    public function testToggleGroupPressesMultipleItems(): void
    {
        $page = $this->visitComponent('toggle-group');
        $example = $page->getByRole('region', ['name' => 'Multiple'])->locator('[data-demo="preview"]');

        $bold = $example->getByRole('button', ['name' => 'Bold']);
        $underline = $example->getByRole('button', ['name' => 'Underline']);
        expect($bold)->toHaveAttribute('aria-pressed', 'true');
        expect($example->getByRole('button', ['name' => 'Italic']))->toHaveAttribute('aria-pressed', 'true');

        $underline->click();
        expect($underline)->toHaveAttribute('aria-pressed', 'true');
        expect($bold)->toHaveAttribute('aria-pressed', 'true');
        $bold->click();
        expect($bold)->toHaveAttribute('aria-pressed', 'false');
    }

    public function testToolbarMovesTheFocusWithTheArrowKeys(): void
    {
        $page = $this->visitComponent('toolbar');
        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');

        expect($hero->getByRole('toolbar'))->toHaveAttribute('aria-orientation', 'horizontal');

        $hero->getByRole('button', ['name' => 'Format as currency'])->focus();
        $page->keyboard()->press('ArrowRight');
        expect($hero->getByRole('button', ['name' => 'Format as percent']))->toBeFocused();

        // Home and End do nothing in a toolbar, as in Base UI: only a toggle group has them.
        $page->keyboard()->press('End');
        expect($hero->getByRole('button', ['name' => 'Format as percent']))->toBeFocused();
        $page->keyboard()->press('Home');
        expect($hero->getByRole('button', ['name' => 'Format as percent']))->toBeFocused();
    }

    public function testToolbarWithPopupsAndANumberField(): void
    {
        $page = $this->visitComponent('toolbar');

        // A menu trigger is an item of the toolbar: the arrow keys go to it, and Enter opens it.
        $menu = $page->getByRole('region', ['name' => 'Using with Menu'])->locator('[data-demo="preview"]');
        $menu->getByRole('button', ['name' => 'Save'])->focus();
        $page->keyboard()->press('ArrowRight');
        $export = $menu->getByRole('button', ['name' => 'Export']);
        expect($export)->toBeFocused();
        expect($export)->toHaveAttribute('data-phem-toolbar-target', 'item');
        $page->keyboard()->press('Enter');
        expect($page->getByRole('menuitem', ['name' => 'PNG']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // The tooltip triggers are items of the toolbar.
        $tooltip = $page->getByRole('region', ['name' => 'Using with Tooltip'])->locator('[data-demo="preview"]');
        $tooltip->getByRole('button', ['name' => 'Bold'])->focus();
        $page->keyboard()->press('ArrowRight');
        expect($tooltip->getByRole('button', ['name' => 'Italic']))->toBeFocused();

        // The input of a number field is an item of the toolbar.
        $zoom = $page->getByRole('region', ['name' => 'Using with NumberField'])->locator('[data-demo="preview"]');
        $zoom->getByRole('button', ['name' => 'Fit'])->focus();
        $page->keyboard()->press('ArrowRight');
        $input = $zoom->getByRole('textbox', ['name' => 'Zoom']);
        expect($input)->toBeFocused();
        $page->keyboard()->press('ArrowUp');
        expect($input)->toHaveValue('110');
    }

    /** In Base UI, the toolbar moves the focus out of a toggle group in it. */
    public function testTheArrowKeysLeaveAToggleGroupInAToolbar(): void
    {
        $page = $this->visitComponent('toolbar');
        $page->getByRole('button', ['name' => 'Align right'])->focus();
        $page->keyboard()->press('ArrowRight');
        expect($page->getByRole('button', ['name' => 'Format as currency']))->toBeFocused();
    }

    public function testAvatarShowsTheFallbackText(): void
    {
        $page = $this->visitComponent('avatar');

        // The second avatar has no image.
        expect($page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByText('LT', ['exact' => true])->last())->toBeVisible();
    }
}
