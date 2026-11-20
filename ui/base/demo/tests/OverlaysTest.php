<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class OverlaysTest extends DemoTestCase
{
    public function testDialogOpensAndCloses(): void
    {
        $page = $this->visitComponent('dialog');

        $trigger = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'View notifications']);
        $dialog = $page->getByRole('dialog', ['name' => 'Notifications']);

        $trigger->click();
        expect($dialog)->toBeVisible();
        expect($trigger)->toHaveAttribute('data-popup-open', '');

        $dialog->getByRole('button', ['name' => 'Close'])->click();
        expect($dialog)->toBeHidden();
        expect($trigger)->toBeFocused();

        // Escape closes it too.
        $trigger->click();
        expect($dialog)->toBeVisible();
        $page->keyboard()->press('Escape');
        expect($dialog)->toBeHidden();
    }

    public function testAlertDialogClosesOnEscapeOnly(): void
    {
        $page = $this->visitComponent('alert-dialog');
        $hero = $page->getByRole('region', ['name' => 'Demo', 'exact' => true])->locator('[data-demo="preview"]');

        $hero->getByRole('button', ['name' => 'Discard draft'])->click();
        $dialog = $page->getByRole('alertdialog', ['name' => 'Discard draft?']);
        expect($dialog)->toBeVisible();

        // A click outside the popup does not close an alert dialog.
        $page->mouse()->click(5, 5);
        expect($dialog)->toBeVisible();

        $page->keyboard()->press('Escape');
        expect($dialog)->toBeHidden();

        $hero->getByRole('button', ['name' => 'Discard draft'])->click();
        $dialog->getByRole('button', ['name' => 'Discard'])->click();
        expect($dialog)->toBeHidden();
    }

    public function testAlertDialogExamples(): void
    {
        $page = $this->visitComponent('alert-dialog');
        $region = fn (string $name) => $page->getByRole('region', ['name' => $name, 'exact' => true])->locator('[data-demo="preview"]');

        // Open from a menu: the item closes the menu and opens the alert dialog.
        $region('Open from a menu')->getByRole('button', ['name' => 'Playlist'])->click();
        $page->getByRole('menuitem', ['name' => 'Delete…'])->click();
        $dialog = $page->getByRole('alertdialog', ['name' => 'Delete playlist?', 'exact' => true]);
        expect($dialog)->toBeVisible();
        expect($page->getByRole('menu'))->toHaveCount(0);
        $dialog->getByRole('button', ['name' => 'Delete'])->click();
        expect($dialog)->toBeHidden();

        // Close confirmation: a close with a text asks first.
        $region('Close confirmation')->getByRole('button', ['name' => 'Tweet'])->click();
        $tweet = $page->getByRole('dialog', ['name' => 'New tweet', 'exact' => true]);
        $tweet->getByRole('textbox')->fill('Hello');
        $tweet->getByRole('button', ['name' => 'Cancel'])->click();
        $confirm = $page->getByRole('alertdialog', ['name' => 'Discard tweet?', 'exact' => true]);
        $confirm->getByRole('button', ['name' => 'Discard'])->click();
        expect($tweet)->toBeHidden();

        // Detached triggers: the trigger outside the root opens it.
        $trigger = $region('Detached triggers')->getByRole('button', ['name' => 'Discard draft']);
        $trigger->click();
        $dialog = $page->getByRole('alertdialog', ['name' => 'Discard draft?', 'exact' => true]);
        expect($dialog)->toBeVisible();
        expect($dialog)->toContainText('This action cannot be undone.');
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        $page->mouse()->click(5, 5);
        expect($dialog)->toBeVisible();
        $dialog->getByRole('button', ['name' => 'Cancel'])->click();
        expect($dialog)->toBeHidden();
        expect($trigger)->toBeFocused();

        // Multiple triggers: the payload of the trigger that opened it.
        $region('Multiple triggers')->getByRole('button', ['name' => 'Trigger 2'])->click();
        $dialog = $page->getByRole('alertdialog', ['name' => 'Alert dialog', 'exact' => true]);
        expect($dialog)->toContainText('Confirming Trigger 2');
        $page->keyboard()->press('Escape');
        expect($dialog)->toBeHidden();
        $region('Multiple triggers')->getByRole('button', ['name' => 'Trigger 1'])->click();
        expect($dialog)->toContainText('Confirming Trigger 1');
        $page->keyboard()->press('Escape');

        // Controlled mode: the button opens it for the second trigger.
        $controlled = $region('Controlled mode with multiple triggers');
        $controlled->getByRole('button', ['name' => 'Open programmatically'])->click();
        $dialog = $page->getByRole('alertdialog', ['name' => 'Delete project?', 'exact' => true]);
        expect($dialog)->toBeVisible();
        expect($controlled->getByRole('button', ['name' => 'Delete', 'exact' => true]))->toHaveAttribute('aria-expanded', 'true');
        $dialog->getByRole('button', ['name' => 'Confirm'])->click();
        expect($dialog)->toBeHidden();
        $controlled->getByRole('button', ['name' => 'Sign out'])->click();
        expect($page->getByRole('alertdialog', ['name' => 'Sign out?', 'exact' => true]))->toBeVisible();
        $page->keyboard()->press('Escape');
    }

    public function testDrawer(): void
    {
        $page = $this->visitComponent('drawer');

        $page->getByRole('region', ['name' => 'Demo', 'exact' => true])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Open drawer'])->click();
        $drawer = $page->getByRole('dialog', ['name' => 'Drawer', 'exact' => true]);
        expect($drawer)->toBeVisible();
        expect($drawer)->toHaveAttribute('data-swipe-direction', 'right');

        // The popup goes past the right edge (the bleed): a swipe away from the edge shows no gap.
        $this->settle($drawer);
        self::assertTrue($drawer->evaluate('el => el.getBoundingClientRect().right > innerWidth + 40'));

        // A swipe to the right closes it. A mouse press on the content selects its text, so the
        // swipe starts below it.
        $box = $drawer->boundingBox();
        $page->mouse()->move($box['x'] + 10, $box['y'] + $box['height'] * 0.8);
        $page->mouse()->down();
        $page->mouse()->move($box['x'] + 300, $box['y'] + $box['height'] * 0.8, ['steps' => 10]);
        $page->mouse()->up();
        expect($drawer)->toBeHidden();
    }

    public function testDrawerExamples(): void
    {
        $page = $this->visitComponent('drawer');
        $region = fn (string $name) => $page->getByRole('region', ['name' => $name, 'exact' => true])->locator('[data-demo="preview"]');
        $dialog = fn (string $name) => $page->getByRole('dialog', ['name' => $name, 'exact' => true]);

        // State and Position: a bottom sheet, also in a viewport.
        $region('State')->getByRole('button', ['name' => 'Open'])->click();
        expect($dialog('Example drawer'))->toHaveAttribute('data-swipe-direction', 'down');
        $dialog('Example drawer')->getByRole('button', ['name' => 'Close'])->click();
        expect($dialog('Example drawer'))->toBeHidden();
        $region('Position')->getByRole('button', ['name' => 'Open bottom drawer'])->click();
        $notifications = $dialog('Notifications');
        expect($notifications)->toBeVisible();
        $notifications->getByRole('button', ['name' => 'Close'])->click();
        expect($notifications)->toBeHidden();

        // Nested drawers: the drawers behind the front one know that a drawer is open in them.
        $region('Nested drawers')->getByRole('button', ['name' => 'Open drawer stack'])->click();
        $dialog('Account')->getByRole('button', ['name' => 'Security settings'])->click();
        $dialog('Security')->getByRole('button', ['name' => 'Advanced options'])->click();
        expect($dialog('Advanced')->getByRole('textbox', ['name' => 'Device name']))->toHaveValue('Personal laptop');
        expect($dialog('Account'))->toHaveAttribute('data-nested-drawer-open', '');
        expect($dialog('Security'))->toHaveAttribute('data-nested-drawer-open', '');
        // The count of each drawer behind: all the open drawers in it.
        self::assertSame('2', $dialog('Account')->evaluate('el => el.style.getPropertyValue("--nested-drawers")'));
        self::assertSame('1', $dialog('Security')->evaluate('el => el.style.getPropertyValue("--nested-drawers")'));
        $dialog('Advanced')->getByRole('button', ['name' => 'Done'])->click();
        expect($dialog('Advanced'))->toBeHidden();
        $page->keyboard()->press('Escape');
        expect($dialog('Security'))->toBeHidden();
        $page->keyboard()->press('Escape');
        expect($dialog('Account'))->toBeHidden();

        // Snap points: the sheet opens at its first snap point, and a swipe up gives its full height.
        $region('Snap points')->getByRole('button', ['name' => 'Open snap drawer'])->click();
        $snap = $page->locator('dialog[open] [data-phem-dialog-target="popup"]');
        expect($snap)->toHaveAttribute('data-swipe-direction', 'down');
        $this->settle($snap);
        self::assertFalse($snap->evaluate('el => el.hasAttribute("data-expanded")'));
        $box = $dialog('Snap points')->getByRole('heading', ['name' => 'Snap points'])->boundingBox();
        $page->mouse()->move($box['x'] + 5, $box['y'] + 5);
        $page->mouse()->down();
        $page->mouse()->move($box['x'] + 5, 0, ['steps' => 10]);
        $page->mouse()->up();
        expect($snap)->toHaveAttribute('data-expanded', '');
        $page->keyboard()->press('Escape');
        expect($dialog('Snap points'))->toBeHidden();

        // Virtual keyboard aware: the fields of the sheet.
        $region('Virtual keyboard aware')->getByRole('button', ['name' => 'Open keyboard-aware drawer'])->click();
        $delivery = $dialog('Delivery details');
        expect($delivery->getByPlaceholder('Add a note for the driver'))->toBeVisible();
        $delivery->getByRole('button', ['name' => 'Save'])->click();
        expect($delivery)->toBeHidden();

        // Indent effect: the page gets smaller while the drawer is open.
        $indent = $region('Indent effect')->locator('[data-phem-drawer-provider-target="indent"]');
        expect($indent)->toHaveAttribute('data-inactive', '');
        $region('Indent effect')->getByRole('button', ['name' => 'Open drawer'])->click();
        expect($indent)->toHaveAttribute('data-active', '');
        $region('Indent effect')->getByRole('button', ['name' => 'Close'])->click();
        expect($indent)->toHaveAttribute('data-inactive', '');

        // Non-modal: a click on the page does not close it, and the page stays active.
        $region('Non-modal')->getByRole('button', ['name' => 'Open non-modal drawer'])->click();
        $nonModal = $dialog('Non-modal drawer');
        expect($nonModal)->toBeVisible();
        $page->getByRole('heading', ['name' => 'Drawer', 'level' => 1])->click();
        expect($nonModal)->toBeVisible();
        $nonModal->getByRole('button', ['name' => 'Close'])->click();
        expect($nonModal)->toBeHidden();

        // Mobile navigation: a long list of links.
        $region('Mobile navigation')->getByRole('button', ['name' => 'Open mobile menu'])->click();
        $menu = $dialog('Menu');
        expect($menu->getByRole('link', ['name' => 'Dialog', 'exact' => true]))->toHaveAttribute('href', '/components/dialog');
        $menu->getByRole('button', ['name' => 'Close menu'])->click();
        expect($menu)->toBeHidden();

        // Swipe to open: a swipe from the right edge of the box opens the drawer.
        $area = $region('Swipe to open')->locator('[data-phem-dialog-target="swipeArea"]');
        $area->scrollIntoViewIfNeeded();
        $box = $area->boundingBox();
        $page->mouse()->move($box['x'] + 20, $box['y'] + 100);
        $page->mouse()->down();
        $page->mouse()->move($box['x'] - 380, $box['y'] + 100, ['steps' => 10]);
        $page->mouse()->up();
        $library = $dialog('Library');
        expect($library)->toBeVisible();
        $this->settle($library->locator('[data-phem-dialog-target="popup"]'));
        $library->getByRole('button', ['name' => 'Close'])->click();
        expect($library)->toBeHidden();

        // Close confirmation: a close with a text asks first.
        $region('Close confirmation')->getByRole('button', ['name' => 'Tweet'])->click();
        $tweet = $dialog('New tweet');
        $tweet->getByRole('textbox')->fill('Hello');
        $page->keyboard()->press('Escape');
        $confirm = $page->getByRole('alertdialog', ['name' => 'Discard tweet?', 'exact' => true]);
        expect($confirm)->toBeVisible();
        $confirm->getByRole('button', ['name' => 'Discard'])->click();
        expect($tweet)->toBeHidden();

        // Action sheet: an action closes it.
        $region('Action sheet with separate destructive action')->getByRole('button', ['name' => 'Open action sheet'])->click();
        $sheet = $dialog('Profile actions');
        expect($sheet->getByRole('button', ['name' => 'Block User']))->toBeVisible();
        $sheet->getByRole('button', ['name' => 'Mute'])->click();
        expect($sheet)->toBeHidden();

        // Detached triggers: the title is the payload of the trigger.
        $region('Detached triggers')->getByRole('button', ['name' => 'Settings'])->click();
        $settings = $dialog('Settings');
        expect($settings)->toBeVisible();
        $settings->getByRole('button', ['name' => 'Close'])->click();
        expect($settings)->toBeHidden();
    }

    // Waits for the end of the transitions of the element. The open transition starts in the frame
    // after the open, when the element loses `data-starting-style`.
    private function settle(\Playwright\Locator\LocatorInterface $locator): void
    {
        $locator->evaluate('el => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))).then(() => Promise.all(el.getAnimations().map((animation) => animation.finished)))');
    }

    public function testPopover(): void
    {
        $page = $this->visitComponent('popover');

        $trigger = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Notifications']);
        $popup = $page->getByRole('dialog');

        $trigger->click();
        expect($popup)->toBeVisible();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        expect($popup)->toHaveAttribute('data-side', 'bottom');

        // A click outside closes it.
        $page->mouse()->click(5, 5);
        expect($popup)->toBeHidden();
        expect($trigger)->toHaveAttribute('aria-expanded', 'false');
    }

    /** Base UI links the popup to its title and its description. */
    public function testThePopoverIsNamedByItsTitle(): void
    {
        $page = $this->visitComponent('popover');
        $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Notifications'])->click();
        expect($page->getByRole('dialog', ['name' => 'Notifications']))->toBeVisible();
    }

    public function testPreviewCardOpensOnHover(): void
    {
        $page = $this->visitComponent('preview-card');

        $hero = $page->getByRole('region', ['name' => 'Demo'])->locator('[data-demo="preview"]');
        $hero->getByRole('link', ['name' => 'typography'])->hover();
        $card = $hero->locator('[data-phem-popover-target="popup"]');
        expect($card)->toBeVisible();
        expect($card)->toContainText('is the art and science of arranging type');

        $page->mouse()->move(5, 5);
        expect($card)->toBeHidden();
    }

    public function testTooltipOpensOnHoverAndFocus(): void
    {
        $page = $this->visitComponent('tooltip');

        // A tooltip is only visual, as in Base UI: the popup has no role, and the trigger has its
        // own label and no `aria-describedby`.
        $bold = $page->getByRole('button', ['name' => 'Bold']);
        $popup = $page->locator('[data-phem-tooltip-target="popup"]:not([hidden])');

        $bold->hover();
        expect($popup)->toHaveText('Bold');
        expect($page->getByRole('tooltip'))->toHaveCount(0);
        self::assertNull($bold->getAttribute('aria-describedby'));

        $page->mouse()->move(5, 5);
        expect($popup)->toHaveCount(0);

        $page->getByRole('button', ['name' => 'Italic'])->focus();
        expect($popup)->toHaveText('Italic');
    }

    public function testTooltipExamples(): void
    {
        $page = $this->visitComponent('tooltip');

        // Detached triggers: the trigger is outside the root.
        $detached = $page->getByRole('region', ['name' => 'Detached triggers'])->locator('[data-demo="preview"]');
        $delete = $detached->getByRole('button', ['name' => 'Delete']);
        $popup = $page->locator('#delete-tooltip [data-phem-tooltip-target="popup"]');
        $delete->hover();
        expect($popup)->toBeVisible();
        expect($popup)->toHaveText('Delete');
        expect($delete)->toHaveAttribute('data-popup-open', '');
        $page->mouse()->move(5, 5);
        expect($popup)->toBeHidden();

        // Multiple triggers: the payload of the trigger shows in the viewport.
        $multiple = $page->getByRole('region', ['name' => 'Multiple triggers', 'exact' => true])->locator('[data-demo="preview"]');
        $popup = $page->locator('#multiple-tooltip [data-phem-tooltip-target="popup"]');
        $multiple->getByRole('button', ['name' => 'Set a timer'])->hover();
        expect($popup)->toHaveText('Set a timer');
        $multiple->getByRole('button', ['name' => 'Listen to audio preview'])->hover();
        expect($popup->locator('[data-current]'))->toHaveText('Listen to audio preview');
        $page->mouse()->move(5, 5);
        expect($popup)->toBeHidden();

        // Controlled mode: the button opens the tooltip on the second trigger.
        $controlled = $page->getByRole('region', ['name' => 'Controlled mode with multiple triggers'])->locator('[data-demo="preview"]');
        $controlled->getByRole('button', ['name' => 'Open programmatically'])->click();
        $popup = $controlled->locator('[data-phem-tooltip-target="popup"]');
        expect($popup)->toBeVisible();
        expect($controlled->locator('#trigger-2'))->toHaveAttribute('data-popup-open', '');
        $box = $popup->boundingBox();
        $trigger = $controlled->locator('#trigger-2')->boundingBox();
        self::assertEqualsWithDelta($trigger['x'] + $trigger['width'] / 2, $box['x'] + $box['width'] / 2, 1.5);

        // Animating: the popup moves to the next trigger, and its content changes with an animation.
        $animated = $page->getByRole('region', ['name' => 'Animating the Tooltip'])->locator('[data-demo="preview"]');
        $popup = $page->locator('#animated-tooltip [data-phem-tooltip-target="popup"]');
        $viewport = $popup->locator('[data-phem-tooltip-target="viewport"]');
        $animated->getByRole('button', ['name' => 'Listen to audio preview'])->hover();
        expect($popup)->toHaveText('Listen to audio preview');
        usleep(400_000);
        $from = $popup->boundingBox();

        $timer = $animated->getByRole('button', ['name' => 'Set a timer']);
        $timer->hover();
        expect($viewport)->toHaveAttribute('data-activation-direction', 'right');
        expect($viewport->locator('[data-current]'))->toHaveText('Set a timer');
        expect($viewport->locator('[data-previous]'))->toHaveText('Listen to audio preview');
        expect($timer)->toHaveAttribute('data-popup-open', '');

        // The old content leaves at the end of its animation, and the popup is at its new place.
        expect($viewport->locator('[data-previous]'))->toHaveCount(0);
        usleep(400_000);
        $to = $popup->boundingBox();
        $box = $timer->boundingBox();
        self::assertEqualsWithDelta($box['x'] + $box['width'] / 2, $to['x'] + $to['width'] / 2, 1.5);
        self::assertNotEqualsWithDelta($from['width'], $to['width'], 1);
    }

    public function testPopoverExamples(): void
    {
        $page = $this->visitComponent('popover');
        $outside = fn () => $page->mouse()->click(5, 5);

        // Opening on hover.
        $hover = $page->getByRole('region', ['name' => 'Opening on hover'])->locator('[data-demo="preview"]');
        $trigger = $hover->getByRole('button', ['name' => 'Notifications']);
        $trigger->hover();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        $page->mouse()->move(5, 5);
        expect($trigger)->toHaveAttribute('aria-expanded', 'false');

        // Detached triggers: the trigger is outside the root.
        $trigger = $page->getByRole('region', ['name' => 'Detached triggers'])->locator('[data-demo="preview"]')->getByRole('button', ['name' => 'Notifications']);
        $trigger->click();
        expect($page->locator('#detached-popover')->getByRole('dialog', ['name' => 'Notifications']))->toBeVisible();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        $outside();
        expect($trigger)->toHaveAttribute('aria-expanded', 'false');

        // Multiple triggers: a click on the other trigger moves the popover, with its payload.
        $multiple = $page->getByRole('region', ['name' => 'Multiple triggers', 'exact' => true])->locator('[data-demo="preview"]');
        $dialog = $page->locator('#multiple-popover')->getByRole('dialog');
        $multiple->getByRole('button', ['name' => 'Trigger 1'])->click();
        expect($dialog)->toContainText('This has been opened by Trigger 1');
        $second = $multiple->getByRole('button', ['name' => 'Trigger 2']);
        $second->click();
        expect($dialog->locator('[data-current]'))->toContainText('This has been opened by Trigger 2');
        expect($second)->toHaveAttribute('aria-expanded', 'true');
        expect($page->locator('#multiple-popover')->getByRole('dialog', ['name' => 'Popover']))->toBeVisible();
        $second->click();
        expect($second)->toHaveAttribute('aria-expanded', 'false');

        // Controlled mode: the button opens the popover on the second trigger.
        $controlled = $page->getByRole('region', ['name' => 'Controlled mode with multiple triggers'])->locator('[data-demo="preview"]');
        $controlled->getByRole('button', ['name' => 'Open programmatically'])->click();
        expect($controlled->getByRole('dialog', ['name' => 'Notifications']))->toBeVisible();
        expect($controlled->getByRole('button', ['name' => 'Trigger 2']))->toHaveAttribute('aria-expanded', 'true');
        $outside();

        // Animating: the popup moves to the next trigger with the new panel.
        $animated = $page->getByRole('region', ['name' => 'Animating the Popover'])->locator('[data-demo="preview"]');
        $dialog = $page->locator('#animated-popover')->getByRole('dialog');
        $animated->getByRole('button', ['name' => 'Notifications'])->click();
        expect($page->locator('#animated-popover')->getByRole('dialog', ['name' => 'Notifications']))->toBeVisible();
        usleep(400_000);
        $from = $dialog->boundingBox();

        $profile = $animated->getByRole('button', ['name' => 'Profile']);
        $profile->click();
        $viewport = $dialog->locator('[data-phem-popover-target="viewport"]');
        expect($viewport)->toHaveAttribute('data-activation-direction', 'right');
        expect($viewport->locator('[data-current]'))->toContainText('Jason Eventon');
        expect($viewport->locator('[data-previous]'))->toHaveCount(0);
        expect($page->locator('#animated-popover')->getByRole('dialog', ['name' => 'Jason Eventon']))->toBeVisible();
        usleep(400_000);
        $to = $dialog->boundingBox();
        $box = $profile->boundingBox();
        self::assertEqualsWithDelta($box['x'] + $box['width'] / 2, $to['x'] + $to['width'] / 2, 1.5);
        self::assertNotEqualsWithDelta($from['height'], $to['height'], 1);
    }

    public function testPreviewCardExamples(): void
    {
        $page = $this->visitComponent('preview-card');

        // Detached triggers: the link is outside the root.
        $detached = $page->getByRole('region', ['name' => 'Detached triggers'])->locator('[data-demo="preview"]');
        $card = $page->locator('#detached-card [data-phem-popover-target="popup"]');
        $detached->getByRole('link', ['name' => 'typography'])->hover();
        expect($card)->toContainText('is the art and science of arranging type');
        $page->mouse()->move(5, 5);
        expect($card)->toBeHidden();

        // Multiple triggers: the payload of the link shows in the viewport.
        $multiple = $page->getByRole('region', ['name' => 'Multiple triggers', 'exact' => true])->locator('[data-demo="preview"]');
        $card = $page->locator('#multiple-card [data-phem-popover-target="popup"]');
        $multiple->getByRole('link', ['name' => 'Trigger 2'])->hover();
        expect($card)->toHaveText('Preview card opened by Trigger 2');
        $multiple->getByRole('link', ['name' => 'Trigger 1'])->hover();
        expect($card->locator('[data-current]'))->toHaveText('Preview card opened by Trigger 1');
        $page->mouse()->move(5, 5);
        expect($card)->toBeHidden();

        // Controlled mode: the button opens the card on the second link, and keeps the focus.
        $controlled = $page->getByRole('region', ['name' => 'Controlled mode with multiple triggers'])->locator('[data-demo="preview"]');
        $button = $controlled->getByRole('button', ['name' => 'Open programmatically']);
        $button->click();
        $card = $controlled->locator('[data-phem-popover-target="popup"]');
        expect($card)->toContainText('is the concept or proposal');
        expect($controlled->getByRole('link', ['name' => 'design']))->toHaveAttribute('data-popup-open', '');
        expect($button)->toBeFocused();
        $page->mouse()->click(5, 5);
        expect($card)->toBeHidden();

        // Animating: the card moves to the next link with its new content.
        $animated = $page->getByRole('region', ['name' => 'Animating the Preview Card'])->locator('[data-demo="preview"]');
        $card = $page->locator('#animated-card [data-phem-popover-target="popup"]');
        $viewport = $card->locator('[data-phem-popover-target="viewport"]');
        $animated->getByRole('link', ['name' => 'typography'])->hover();
        expect($card)->toContainText('is the art and science of arranging type');
        usleep(400_000);
        $from = $card->boundingBox();

        $art = $animated->getByRole('link', ['name' => 'art']);
        $art->hover();
        expect($viewport)->toHaveAttribute('data-activation-direction', 'right');
        expect($viewport->locator('[data-current]'))->toContainText('is a diverse range of cultural activity');
        expect($viewport->locator('[data-previous]'))->toHaveCount(0);
        usleep(400_000);
        $to = $card->boundingBox();
        self::assertNotEqualsWithDelta($from['height'], $to['height'], 1);
        self::assertGreaterThan($from['x'], $to['x']);
    }

    public function testDialogExamples(): void
    {
        $page = $this->visitComponent('dialog');
        $region = fn (string $name) => $page->getByRole('region', ['name' => $name, 'exact' => true])->locator('[data-demo="preview"]');

        // State: the form closes the dialog when it submits.
        $region('State')->getByRole('button', ['name' => 'Open'])->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Example dialog', 'exact' => true]);
        expect($dialog)->toBeVisible();
        $dialog->getByRole('textbox', ['name' => 'Name'])->fill('Ada');
        $dialog->getByRole('button', ['name' => 'Save'])->click();
        expect($dialog)->toBeHidden();

        // Open from a menu: the item opens the dialog.
        $region('Open from a menu')->getByRole('button', ['name' => 'Playlist'])->click();
        $page->getByRole('menuitem', ['name' => 'Details…'])->click();
        $dialog = $page->getByRole('dialog', ['name' => 'Playlist details', 'exact' => true]);
        expect($dialog)->toBeVisible();
        expect($page->getByRole('menu'))->toHaveCount(0);
        $page->keyboard()->press('Escape');
        expect($dialog)->toBeHidden();

        // Nested dialogs: the parent shows that a dialog is open in it.
        $region('Nested dialogs')->getByRole('button', ['name' => 'View notifications'])->click();
        $parent = $page->getByRole('dialog', ['name' => 'Notifications', 'exact' => true]);
        $parent->getByRole('button', ['name' => 'Customize'])->click();
        $child = $page->getByRole('dialog', ['name' => 'Customize notifications', 'exact' => true]);
        expect($child)->toBeVisible();
        expect($parent)->toHaveAttribute('data-nested-dialog-open', '');
        $child->getByRole('button', ['name' => 'Close'])->click();
        expect($child)->toBeHidden();
        $page->keyboard()->press('Escape');
        expect($parent)->toBeHidden();

        // Close confirmation: a close with a text asks first.
        $region('Close confirmation')->getByRole('button', ['name' => 'Tweet'])->click();
        $tweet = $page->getByRole('dialog', ['name' => 'New tweet', 'exact' => true]);
        $tweet->getByRole('textbox')->fill('Hello');
        $page->keyboard()->press('Escape');
        $confirm = $page->getByRole('alertdialog', ['name' => 'Discard tweet?', 'exact' => true]);
        expect($confirm)->toBeVisible();
        $confirm->getByRole('button', ['name' => 'Go back'])->click();
        expect($confirm)->toBeHidden();
        expect($tweet)->toBeVisible();
        $tweet->getByRole('button', ['name' => 'Cancel'])->click();
        $confirm->getByRole('button', ['name' => 'Discard'])->click();
        expect($tweet)->toBeHidden();
        $region('Close confirmation')->getByRole('button', ['name' => 'Tweet'])->click();
        expect($tweet->getByRole('textbox'))->toHaveValue('');
        $tweet->getByRole('button', ['name' => 'Cancel'])->click();
        expect($tweet)->toBeHidden();

        // Custom focus management: the first focus and the last focus.
        $region('Custom focus management')->getByRole('button', ['name' => 'Open feedback'])->click();
        $feedback = $page->getByRole('dialog', ['name' => 'Feedback form', 'exact' => true]);
        expect($feedback->getByPlaceholder('Enter your feedback'))->toBeFocused();
        $feedback->getByRole('button', ['name' => 'Close'])->click();
        expect($page->getByRole('button', ['name' => 'Final focus']))->toBeFocused();

        // Outside scroll: the viewport scrolls to the end of the popup.
        $region('Outside scroll dialog')->getByRole('button', ['name' => 'Open dialog'])->click();
        $outside = $page->getByRole('dialog', ['name' => 'Dialog', 'exact' => true]);
        expect($outside)->toBeVisible();
        $onScreen = 'el => { const box = el.getBoundingClientRect(); return box.top >= 0 && box.bottom <= innerHeight }';
        $popup = $outside->locator('[data-phem-dialog-target="popup"]');
        self::assertTrue($popup->evaluate('el => el.offsetHeight > innerHeight'));
        // The first focus goes to the popup, and the viewport does not scroll to it.
        expect($popup)->toBeFocused();
        self::assertSame(0, $outside->evaluate('el => el.scrollTop'));
        $outside->getByRole('link', ['name' => 'Popover'])->scrollIntoViewIfNeeded();
        self::assertTrue($outside->getByRole('link', ['name' => 'Popover'])->evaluate($onScreen));
        $outside->getByRole('button', ['name' => 'Close'])->click();
        expect($outside)->toBeHidden();

        // Inside scroll: the popup stays on the screen, and its Close button too.
        $region('Inside scroll dialog')->getByRole('button', ['name' => 'Open dialog'])->click();
        $inside = $page->getByRole('dialog', ['name' => 'Dialog', 'exact' => true]);
        expect($inside)->toBeVisible();
        expect($inside->getByRole('button', ['name' => 'Close']))->toBeFocused();
        self::assertTrue($inside->getByRole('button', ['name' => 'Close'])->evaluate($onScreen));
        $inside->getByRole('button', ['name' => 'Close'])->click();
        expect($inside)->toBeHidden();

        // Placing elements outside the popup: a click next to the box closes the dialog.
        $region('Placing elements outside the popup')->getByRole('button', ['name' => 'Open dialog'])->click();
        $close = $page->locator('dialog[open] button[aria-label="Close"]');
        expect($close)->toBeVisible();
        $page->mouse()->click(5, 5);
        expect($close)->toBeHidden();

        // Detached triggers.
        $trigger = $region('Detached triggers')->getByRole('button', ['name' => 'View notifications']);
        $trigger->click();
        expect($page->getByRole('dialog', ['name' => 'Notifications', 'exact' => true]))->toBeVisible();
        expect($trigger)->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');
        expect($trigger)->toBeFocused();

        // Multiple triggers: the payload of the trigger.
        $region('Multiple triggers')->getByRole('button', ['name' => 'Trigger 2'])->click();
        $multiple = $page->getByRole('dialog', ['name' => 'Dialog', 'exact' => true]);
        expect($multiple)->toContainText('This has been opened by Trigger 2');
        $page->keyboard()->press('Escape');
        expect($multiple)->toBeHidden();

        // Controlled mode: the button opens the dialog for the second trigger.
        $controlled = $region('Controlled mode with multiple triggers');
        $controlled->getByRole('button', ['name' => 'Open programmatically'])->click();
        expect($page->getByRole('dialog', ['name' => 'Dialog 2', 'exact' => true]))->toBeVisible();
        expect($controlled->getByRole('button', ['name' => 'Open 2']))->toHaveAttribute('aria-expanded', 'true');
        $page->keyboard()->press('Escape');
        $controlled->getByRole('button', ['name' => 'Open 3'])->click();
        expect($page->getByRole('dialog', ['name' => 'Dialog 3', 'exact' => true]))->toBeVisible();
    }
}
