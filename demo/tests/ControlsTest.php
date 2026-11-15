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

        $switch = $page->getByRole('switch');
        expect($switch)->toHaveAttribute('aria-checked', 'true');
        expect($switch)->toHaveAttribute('data-checked', '');

        // A click on the text of the label toggles it.
        $page->getByText('Notifications', ['exact' => true])->click();
        expect($switch)->toHaveAttribute('aria-checked', 'false');
        expect($switch)->toHaveAttribute('data-unchecked', '');

        $page->keyboard()->press('Space');
        expect($switch)->toHaveAttribute('aria-checked', 'true');
    }

    public function testCheckbox(): void
    {
        $page = $this->visitComponent('checkbox');

        $checkbox = $page->getByRole('checkbox');
        expect($checkbox)->toHaveAttribute('aria-checked', 'true');

        $checkbox->click();
        expect($checkbox)->toHaveAttribute('aria-checked', 'false');
        expect($checkbox)->toHaveAttribute('data-unchecked', '');
    }

    public function testCheckboxGroupKeepsItsValue(): void
    {
        $page = $this->visitComponent('checkbox-group');

        $fuji = $page->locator('[role="checkbox"][data-value="fuji-apple"]');
        $gala = $page->locator('[role="checkbox"][data-value="gala-apple"]');

        expect($fuji)->toHaveAttribute('aria-checked', 'true');
        expect($gala)->toHaveAttribute('aria-checked', 'false');

        $gala->click();
        expect($gala)->toHaveAttribute('aria-checked', 'true');
        expect($fuji)->toHaveAttribute('aria-checked', 'true');
        expect($page->getByRole('group', ['name' => 'Apples']))->toHaveAttribute('data-phem-checkbox-group-value-value', '["fuji-apple","gala-apple"]');
    }

    /**
     * Base UI gives a wrapping `<label>` an id, and links it to the checkbox or the switch with
     * `aria-labelledby`. A `<span role="checkbox">` gets no name from its label without it.
     */
    public function testTheWrappingLabelNamesTheCheckboxAndTheSwitch(): void
    {
        self::markTestIncomplete('phem-toggle does not link a wrapping <label> to the element yet (notes/phem-gaps.md, gap 14).');

        $page = $this->visitComponent('checkbox');
        expect($page->getByRole('checkbox', ['name' => 'Enable notifications']))->toHaveCount(1);

        $page = $this->visitComponent('switch');
        expect($page->getByRole('switch', ['name' => 'Notifications']))->toHaveCount(1);
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

    public function testToolbarMovesTheFocusWithTheArrowKeys(): void
    {
        $page = $this->visitComponent('toolbar');

        expect($page->getByRole('toolbar'))->toHaveAttribute('aria-orientation', 'horizontal');

        $page->getByRole('button', ['name' => 'Format as currency'])->focus();
        $page->keyboard()->press('ArrowRight');
        expect($page->getByRole('button', ['name' => 'Format as percent']))->toBeFocused();
        $page->keyboard()->press('End');
        expect($page->getByRole('link', ['name' => 'Edited 51m ago']))->toBeFocused();
        $page->keyboard()->press('Home');
        expect($page->getByRole('button', ['name' => 'Align left']))->toBeFocused();
    }

    /** In Base UI, the toolbar moves the focus out of a toggle group in it. */
    public function testTheArrowKeysLeaveAToggleGroupInAToolbar(): void
    {
        self::markTestIncomplete('phem-toggle-group keeps its own roving focus in a phem-toolbar (notes/phem-gaps.md, gap 16).');

        $page = $this->visitComponent('toolbar');
        $page->getByRole('button', ['name' => 'Align right'])->focus();
        $page->keyboard()->press('ArrowRight');
        expect($page->getByRole('button', ['name' => 'Format as currency']))->toBeFocused();
    }

    public function testAvatarShowsTheFallbackText(): void
    {
        $page = $this->visitComponent('avatar');

        // The second avatar has no image.
        expect($page->getByText('LT', ['exact' => true])->last())->toBeVisible();
    }
}
