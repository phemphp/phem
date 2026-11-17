<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class MenusTest extends DemoTestCase
{
    public function testMenuOpensAndTheKeyboardMovesTheHighlight(): void
    {
        $page = $this->visitComponent('menu');

        $trigger = $page->getByRole('region', ['name' => 'Demo', 'exact' => true])->getByRole('button', ['name' => 'Song']);
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

    public function testMenuExamples(): void
    {
        $page = $this->visitComponent('menu');
        $region = fn (string $name) => $page->getByRole('region', ['name' => $name, 'exact' => true]);
        $menu = $page->getByRole('menu');
        $texts = function ($locator, array $texts): void {
            expect($locator)->toHaveCount(\count($texts));
            self::assertSame($texts, $locator->evaluateAll('els => els.map((el) => el.textContent.trim())'));
        };

        // Open on hover: the pointer opens the menu.
        $region('Open on hover')->getByRole('button', ['name' => 'Add to playlist'])->hover();
        expect($page->getByRole('menuitem', ['name' => 'Night Beats']))->toBeVisible();
        $page->mouse()->move(5, 5);
        expect($menu)->toHaveCount(0);

        // Checkbox items: the server keeps the state, and the menu stays open.
        $trigger = $region('Checkbox items')->getByRole('button', ['name' => 'Workspace']);
        $trigger->click();
        $minimap = $page->getByRole('menuitemcheckbox', ['name' => 'Minimap']);
        $sidebar = $page->getByRole('menuitemcheckbox', ['name' => 'Sidebar']);
        expect($minimap)->toHaveAttribute('aria-checked', 'true');
        $minimap->click();
        expect($minimap)->toHaveAttribute('aria-checked', 'false');
        expect($minimap)->toHaveAttribute('data-unchecked', '');
        $sidebar->click();
        expect($sidebar)->toHaveAttribute('aria-checked', 'true');
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');

        // Radio items: a radio item unchecks the other items of its group.
        $region('Radio items')->getByRole('button', ['name' => 'Sort'])->click();
        $date = $page->getByRole('menuitemradio', ['name' => 'Date']);
        $type = $page->getByRole('menuitemradio', ['name' => 'Type']);
        expect($date)->toHaveAttribute('aria-checked', 'true');
        $type->click();
        expect($type)->toHaveAttribute('aria-checked', 'true');
        expect($date)->toHaveAttribute('aria-checked', 'false');
        $page->keyboard()->press('Escape');

        // Close on click: the item keeps the menu open, and the checkbox item closes it.
        $region('Close on click')->getByRole('button', ['name' => 'Format'])->click();
        $page->getByRole('menuitem', ['name' => 'Keep open'])->click();
        expect($menu)->toBeVisible();
        $page->getByRole('menuitemcheckbox', ['name' => 'Bold'])->click();
        expect($menu)->toHaveCount(0);

        // Group labels: the groups have the name of their label.
        $region('Group labels')->getByRole('button', ['name' => 'View'])->click();
        expect($page->getByRole('group', ['name' => 'Sort']))->toBeVisible();
        expect($page->getByRole('group', ['name' => 'Workspace'])->getByRole('menuitemcheckbox'))->toHaveCount(3);
        $page->keyboard()->press('Escape');

        // Nested menu: the pointer opens the submenu, and ArrowLeft closes it.
        $region('Nested menu')->getByRole('button', ['name' => 'Song'])->click();
        $playlist = $page->getByRole('menuitem', ['name' => 'Add to Playlist']);
        $playlist->hover();
        expect($page->getByRole('menuitem', ['name' => 'Night Beats']))->toBeVisible();
        $page->getByRole('menuitem', ['name' => 'Get Up!'])->hover();
        $page->keyboard()->press('ArrowLeft');
        expect($page->getByRole('menuitem', ['name' => 'Night Beats']))->toBeHidden();
        expect($playlist)->toBeFocused();
        $page->keyboard()->press('Escape');

        // Navigate to another page: the items are links.
        $region('Navigate to another page')->getByRole('button', ['name' => 'Components'])->click();
        expect($page->getByRole('menuitem', ['name' => 'Go to Dialog']))->toHaveAttribute('href', '/components/dialog');
        $page->keyboard()->press('Escape');

        // Open a dialog: the item closes the menu and opens the dialog.
        $region('Open a dialog')->getByRole('button', ['name' => 'Open menu'])->click();
        $page->getByRole('menuitem', ['name' => 'Open dialog'])->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Dialog from a menu', 'exact' => true]);
        expect($dialog)->toBeVisible();
        expect($menu)->toHaveCount(0);
        $dialog->getByRole('button', ['name' => 'Close'])->click();
        expect($dialog)->toBeHidden();

        // Detached triggers: the trigger outside the root opens the menu, and gets the focus back.
        $actions = $region('Detached triggers')->getByRole('button', ['name' => 'Project actions']);
        $actions->click();
        expect($page->getByRole('menuitem', ['name' => 'Move to folder']))->toBeVisible();
        expect($actions)->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');
        expect($menu)->toHaveCount(0);
        expect($actions)->toBeFocused();

        // Multiple triggers: the content of the payload of each trigger. The menu is modal: a press
        // on the other trigger closes it, as in Base UI.
        $file = $region('Multiple triggers')->getByRole('button', ['name' => 'File']);
        $edit = $region('Multiple triggers')->getByRole('button', ['name' => 'Edit']);
        $file->click();
        $texts($menu->getByRole('menuitem'), ['New', 'Open', 'Save']);
        $page->keyboard()->press('Escape');
        $edit->click();
        $texts($menu->getByRole('menuitem'), ['Undo', 'Redo', 'Cut', 'Copy', 'Paste']);
        expect($edit)->toHaveAttribute('aria-expanded', 'true');
        expect($file)->toHaveAttribute('aria-expanded', 'false');
        $page->keyboard()->press('Escape');
        expect($menu)->toHaveCount(0);

        // Controlled mode: the button opens the menu next to the second trigger.
        $controlled = $region('Controlled mode with multiple triggers');
        $controlled->getByRole('button', ['name' => 'Open playback (controlled)'])->click();
        $texts($menu->getByRole('menuitem'), ['Play', 'Add to queue']);
        expect($controlled->getByRole('button', ['name' => 'Playback', 'exact' => true]))->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');

        // Arrow: the arrow is on the side of the trigger.
        $region('Arrow')->getByRole('button', ['name' => 'Song'])->click();
        expect($menu->locator('[data-phem-menu-target="arrow"]'))->toHaveAttribute('data-side', 'bottom');
        $page->keyboard()->press('Escape');

        // Animating the Menu: a menu that is not modal moves to the other trigger, and the popup
        // gets the size variables of its content.
        $animated = $region('Animating the Menu');
        $animated->getByRole('button', ['name' => 'Library'])->click();
        expect($page->getByRole('group', ['name' => 'Library']))->toBeVisible();
        $animated->getByRole('button', ['name' => 'Share'])->click();
        expect($page->getByRole('group', ['name' => 'Share']))->toBeVisible();
        expect($animated->getByRole('button', ['name' => 'Share']))->toHaveAttribute('aria-expanded', 'true');
        self::assertStringContainsString('--popup-width', (string) $menu->getAttribute('style'));
        expect($menu->locator('[data-phem-menu-target="viewport"]'))->toHaveAttribute('data-activation-direction', 'right');
        $page->keyboard()->press('Escape');
        expect($menu)->toHaveCount(0);

        // Filtering: the input keeps the focus, and the arrow keys move the highlight.
        $region('Filtering')->getByRole('button', ['name' => 'Actions'])->click();
        $filter = $page->getByRole('dialog')->getByRole('searchbox', ['name' => 'Filter actions']);
        expect($filter)->toBeFocused();
        $filter->fill('save');
        $texts($menu->getByRole('menuitem'), ['Save', 'Save as']);
        expect($page->getByRole('group', ['name' => 'Organize']))->toBeHidden();
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        expect($page->getByRole('menuitem', ['name' => 'Save as']))->toHaveAttribute('data-highlighted', '');
        expect($filter)->toBeFocused();
        $filter->fill('zzz');
        expect($page->getByText('No actions found.'))->toBeVisible();
        $page->getByRole('button', ['name' => 'Clear'])->first()->click();
        expect($filter)->toHaveValue('');
        expect($filter)->toBeFocused();

        // A submenu has its own filter.
        $filter->fill('move');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        $folders = $page->getByRole('searchbox', ['name' => 'Filter folders']);
        expect($folders)->toBeFocused();
        $folders->fill('proj');
        expect($page->getByRole('menuitem', ['name' => 'Projects']))->toBeVisible();
        expect($page->getByRole('menuitem', ['name' => 'Desktop']))->toBeHidden();
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($page->getByRole('dialog'))->toHaveCount(0);
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
