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

        $page->getByRole('region', ['name' => 'Demo'])->getByText('Right click here')->click(['button' => 'right']);
        $menu = $page->getByRole('menu');
        expect($menu)->toBeVisible();
        expect($page->getByRole('menuitem', ['name' => 'Favorite']))->toBeVisible();

        $page->getByRole('menuitem', ['name' => 'Play Next'])->click();
        expect($menu)->toBeHidden();
    }

    public function testContextMenuExamples(): void
    {
        $page = $this->visitComponent('context-menu');

        // The same actions in the context menu of the card and in its menu button.
        $card = $page->getByRole('region', ['name' => 'Using with Menu']);
        $card->getByText('Station Hofplein')->click(['button' => 'right']);
        expect($page->getByRole('menuitem', ['name' => 'Copy link']))->toBeVisible();
        $page->keyboard()->press('Escape');
        $button = $card->getByRole('button', ['name' => 'Image actions']);
        expect($button->locator('svg'))->toHaveCount(1);
        $button->click();
        expect($page->getByRole('menuitem', ['name' => 'Delete']))->toBeVisible();
        $page->keyboard()->press('Escape');

        // The submenu of a context menu.
        $page->getByRole('region', ['name' => 'Nested menu'])->getByText('Right click here')->click(['button' => 'right']);
        $page->getByRole('menuitem', ['name' => 'Add to Playlist'])->hover();
        expect($page->getByRole('menuitem', ['name' => 'New playlist…']))->toBeVisible();
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

        // Enter on a trigger opens its menu with the first item highlighted, as in Base UI.
        $file->focus();
        $page->keyboard()->press('Enter');
        $new = $page->getByRole('menuitem', ['name' => 'New']);
        expect($new)->toBeFocused();
        expect($new)->toHaveAttribute('data-highlighted', '');
        expect($new)->toHaveAttribute('tabindex', '0');
    }

    public function testNavigationMenuOpensItsContentInTheViewport(): void
    {
        $page = $this->visitComponent('navigation-menu');
        $hero = $page->getByRole('region', ['name' => 'Demo']);

        $overview = $hero->getByRole('button', ['name' => 'Overview']);
        $overview->click();
        expect($overview)->toHaveAttribute('aria-expanded', 'true');
        expect($page->getByRole('link', ['name' => 'Quick Start']))->toBeVisible();

        $hero->getByRole('button', ['name' => 'Handbook'])->hover();
        expect($page->getByRole('link', ['name' => 'Styling']))->toBeVisible();
        expect($page->getByRole('link', ['name' => 'Quick Start']))->toBeHidden();

        $page->keyboard()->press('Escape');
        expect($page->getByRole('link', ['name' => 'Styling']))->toBeHidden();
    }

    public function testNavigationMenuNestedSubmenus(): void
    {
        $page = $this->visitComponent('navigation-menu');
        $example = $page->getByRole('region', ['name' => 'Nested submenus']);

        $overview = $example->getByRole('button', ['name' => 'Overview']);
        $overview->click();
        expect($page->getByRole('link', ['name' => 'Accessibility Learn how we build accessible components.']))->toBeVisible();

        // The trigger of the inner menu opens its content next to it, and the outer menu stays open.
        $handbook = $page->getByRole('button', ['name' => 'Handbook How to use Base UI effectively.']);
        $handbook->click();
        expect($handbook)->toHaveAttribute('aria-expanded', 'true');
        expect($page->getByRole('link', ['name' => 'Animation Base UI components can be animated with CSS transitions, CSS animations, or JavaScript libraries.']))->toBeVisible();
        expect($overview)->toHaveAttribute('aria-expanded', 'true');

        // Escape closes the inner menu first, then the outer menu.
        $page->keyboard()->press('Escape');
        expect($handbook)->toHaveAttribute('aria-expanded', 'false');
        expect($overview)->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');
        expect($overview)->toHaveAttribute('aria-expanded', 'false');

        // With the pointer: hover opens the two menus, and the pointer in the inner popup keeps
        // both of them open.
        $overview->hover();
        expect($handbook)->toBeVisible();
        $handbook->hover();
        $animation = $page->getByRole('link', ['name' => 'Animation Base UI components can be animated with CSS transitions, CSS animations, or JavaScript libraries.']);
        expect($animation)->toBeVisible();
        $animation->hover();
        usleep(300_000);
        expect($animation)->toBeVisible();
        expect($overview)->toHaveAttribute('aria-expanded', 'true');
    }

    public function testNavigationMenuNestedInlineSubmenus(): void
    {
        $page = $this->visitComponent('navigation-menu');
        $example = $page->getByRole('region', ['name' => 'Nested inline submenus']);

        // The inner menu shows its default item in the panel of the outer menu.
        $product = $example->getByRole('button', ['name' => 'Product']);
        $product->click();
        expect($page->getByRole('heading', ['name' => 'Build product UI without giving up control']))->toBeVisible();

        // Another item of the inner menu changes the panel, and the outer menu stays open.
        $systems = $page->getByRole('button', ['name' => 'Design Systems Keep patterns aligned across teams.']);
        $systems->click();
        expect($page->getByRole('heading', ['name' => 'Turn shared standards into working components']))->toBeVisible();
        expect($page->getByRole('heading', ['name' => 'Build product UI without giving up control']))->toBeHidden();
        expect($product)->toHaveAttribute('aria-expanded', 'true');

        // The pointer on another item of the inner menu changes the panel too.
        $page->getByRole('button', ['name' => 'Developers Go from idea to UI faster.'])->hover();
        expect($page->getByRole('heading', ['name' => 'Build product UI without giving up control']))->toBeVisible();
        expect($product)->toHaveAttribute('aria-expanded', 'true');
    }

    public function testNavigationMenuLargeMenu(): void
    {
        $page = $this->visitComponent('navigation-menu');
        $example = $page->getByRole('region', ['name' => 'Large menus']);

        $example->getByRole('button', ['name' => 'Components'])->click();
        expect($example->getByRole('link', ['name' => 'Accordion']))->toBeVisible();
        expect($example->locator('[data-controller~="phem-scroll-area"]'))->toHaveAttribute('data-has-overflow-y', '');
    }
}
