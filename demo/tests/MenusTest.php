<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class MenusTest extends DemoTestCase
{
    public function testMenuWithASubmenuAndACheckboxItem(): void
    {
        $page = $this->visitComponent('menu');

        $trigger = $page->getByRole('button', ['name' => 'Song']);
        $trigger->click();
        $menu = $page->getByRole('menu')->first();
        expect($menu)->toBeVisible();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');

        // The arrow keys highlight the items.
        $page->keyboard()->press('ArrowDown');
        expect($page->getByRole('menuitem', ['name' => 'Add to Library']))->toHaveAttribute('data-highlighted', '');

        // ArrowRight opens the submenu.
        $page->getByRole('menuitem', ['name' => 'Add to Playlist'])->hover();
        expect($page->getByRole('menuitem', ['name' => 'Night Beats']))->toBeVisible();

        // The checkbox item takes its state from the server, and the menu stays open.
        $minimap = $page->getByRole('menuitemcheckbox', ['name' => 'Minimap']);
        expect($minimap)->toHaveAttribute('aria-checked', 'true');
        $minimap->click();
        expect($minimap)->toHaveAttribute('aria-checked', 'false');
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');

        $page->keyboard()->press('Escape');
        expect($page->getByRole('menu'))->toHaveCount(0);
        expect($trigger)->toBeFocused();
    }

    public function testCheckboxItemsAndRadioItemsKeepTheirStateInTheBrowser(): void
    {
        $page = $this->visitComponent('menu-check-items');

        $trigger = $page->getByRole('button', ['name' => 'Workspace']);
        $trigger->click();

        // A checkbox item with `defaultChecked` is checked. A click unchecks it, with no event.
        $minimap = $page->getByRole('menuitemcheckbox', ['name' => 'Minimap']);
        $sidebar = $page->getByRole('menuitemcheckbox', ['name' => 'Sidebar']);
        expect($minimap)->toHaveAttribute('aria-checked', 'true');
        expect($sidebar)->toHaveAttribute('aria-checked', 'false');
        $minimap->click();
        $sidebar->click();
        expect($minimap)->toHaveAttribute('aria-checked', 'false');
        expect($minimap)->toHaveAttribute('data-unchecked', '');
        expect($sidebar)->toHaveAttribute('data-checked', '');

        // A radio item unchecks the other items of its group.
        $date = $page->getByRole('menuitemradio', ['name' => 'Date']);
        $type = $page->getByRole('menuitemradio', ['name' => 'Type']);
        expect($date)->toHaveAttribute('aria-checked', 'true');
        $type->click();
        expect($type)->toHaveAttribute('aria-checked', 'true');
        expect($date)->toHaveAttribute('aria-checked', 'false');
        expect($page->getByRole('menuitemradio', ['name' => 'Name']))->toHaveAttribute('aria-checked', 'false');

        // The menu stays open, and the state stays when the menu opens again.
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');
        $trigger->click();
        expect($sidebar)->toHaveAttribute('aria-checked', 'true');
        expect($type)->toHaveAttribute('aria-checked', 'true');
    }

    public function testContextMenuOpensOnRightClick(): void
    {
        $page = $this->visitComponent('context-menu');

        $page->getByText('Right click here')->click(['button' => 'right']);
        $menu = $page->getByRole('menu');
        expect($menu)->toBeVisible();
        expect($page->getByRole('menuitem', ['name' => 'Favorite']))->toBeVisible();

        $page->getByRole('menuitem', ['name' => 'Play Next'])->click();
        expect($menu)->toBeHidden();
    }

    public function testMenubarMovesBetweenTheMenus(): void
    {
        $page = $this->visitComponent('menubar');

        expect($page->getByRole('menubar'))->toBeVisible();
        $file = $page->getByRole('menuitem', ['name' => 'File']);
        $edit = $page->getByRole('menuitem', ['name' => 'Edit']);

        $file->click();
        expect($page->getByRole('menuitem', ['name' => 'Print']))->toBeVisible();

        // With one menu open, a hover on another trigger opens its menu.
        $edit->hover();
        expect($page->getByRole('menuitem', ['name' => 'Paste']))->toBeVisible();
        expect($page->getByRole('menuitem', ['name' => 'Print']))->toBeHidden();

        // ArrowRight moves to the next menu.
        $page->keyboard()->press('ArrowRight');
        expect($page->getByRole('menuitem', ['name' => 'Full Screen']))->toBeVisible();

        $page->keyboard()->press('Escape');
        expect($page->getByRole('menuitem', ['name' => 'Full Screen']))->toBeHidden();
        expect($page->getByRole('menuitem', ['name' => 'Help']))->toBeDisabled();
    }

    public function testNavigationMenuOpensItsContentInTheViewport(): void
    {
        $page = $this->visitComponent('navigation-menu');

        $overview = $page->getByRole('button', ['name' => 'Overview']);
        $overview->click();
        expect($overview)->toHaveAttribute('aria-expanded', 'true');
        expect($page->getByRole('link', ['name' => 'Quick Start']))->toBeVisible();

        $page->getByRole('button', ['name' => 'Handbook'])->hover();
        expect($page->getByRole('link', ['name' => 'Styling']))->toBeVisible();
        expect($page->getByRole('link', ['name' => 'Quick Start']))->toBeHidden();

        $page->keyboard()->press('Escape');
        expect($page->getByRole('link', ['name' => 'Styling']))->toBeHidden();
    }
}
