<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class MenusTest extends DemoTestCase
{
    public function testMenuOpensAndTheKeyboardMovesTheHighlight(): void
    {
        $page = $this->visitComponent('menu');

        $trigger = $page->getByRole('button', ['name' => 'Song']);
        $trigger->click();
        expect($page->getByRole('menu'))->toBeVisible();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        expect($page->getByRole('menuitem'))->toHaveCount(6);

        // The arrow keys highlight the items, and End goes to the last item.
        $page->keyboard()->press('ArrowDown');
        expect($page->getByRole('menuitem', ['name' => 'Add to Library']))->toHaveAttribute('data-highlighted', '');
        $page->keyboard()->press('End');
        expect($page->getByRole('menuitem', ['name' => 'Share']))->toHaveAttribute('data-highlighted', '');

        // A letter goes to the next item that starts with it.
        $page->keyboard()->press('f');
        expect($page->getByRole('menuitem', ['name' => 'Favorite']))->toHaveAttribute('data-highlighted', '');

        // The menu is modal, as in Base UI: the page does not scroll while it is open.
        self::assertSame('hidden', $page->evaluate('getComputedStyle(document.documentElement).overflow'));

        $page->keyboard()->press('Escape');
        expect($page->getByRole('menu'))->toHaveCount(0);
        expect($trigger)->toBeFocused();
        self::assertNotSame('hidden', $page->evaluate('getComputedStyle(document.documentElement).overflow'));

        // A click on an item closes the menu.
        $trigger->click();
        $page->getByRole('menuitem', ['name' => 'Play Next'])->click();
        expect($page->getByRole('menu'))->toHaveCount(0);
    }

    public function testSubmenuCheckboxItemsAndRadioItems(): void
    {
        $page = $this->visitComponent('menu');

        $trigger = $page->getByRole('button', ['name' => 'Workspace']);
        $trigger->click();

        // The pointer opens the submenu, and ArrowLeft closes it.
        $playlist = $page->getByRole('menuitem', ['name' => 'Add to Playlist']);
        $playlist->hover();
        expect($page->getByRole('menuitem', ['name' => 'Night Beats']))->toBeVisible();
        $page->getByRole('menuitem', ['name' => 'Get Up!'])->hover();
        $page->keyboard()->press('ArrowLeft');
        expect($page->getByRole('menuitem', ['name' => 'Night Beats']))->toBeHidden();
        expect($playlist)->toBeFocused();

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

        // A checkbox item with a handler takes its state from the server. The state of the
        // browser of the other items stays after the morph, and the menu stays open.
        $autosave = $page->getByRole('menuitemcheckbox', ['name' => 'Autosave']);
        expect($autosave)->toHaveAttribute('aria-checked', 'true');
        $autosave->click();
        expect($autosave)->toHaveAttribute('aria-checked', 'false');
        expect($sidebar)->toHaveAttribute('aria-checked', 'true');
        expect($type)->toHaveAttribute('aria-checked', 'true');
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');

        // The state stays when the menu opens again.
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
