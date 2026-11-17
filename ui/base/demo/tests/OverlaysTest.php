<?php

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

final class OverlaysTest extends DemoTestCase
{
    public function testDialogOpensAndCloses(): void
    {
        $page = $this->visitComponent('dialog');

        $trigger = $page->getByRole('button', ['name' => 'View notifications']);
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

        $page->getByRole('button', ['name' => 'Discard draft'])->click();
        $dialog = $page->getByRole('alertdialog', ['name' => 'Discard draft?']);
        expect($dialog)->toBeVisible();

        // A click outside the popup does not close an alert dialog.
        $page->mouse()->click(5, 5);
        expect($dialog)->toBeVisible();

        $page->keyboard()->press('Escape');
        expect($dialog)->toBeHidden();

        $page->getByRole('button', ['name' => 'Discard draft'])->click();
        $dialog->getByRole('button', ['name' => 'Discard'])->click();
        expect($dialog)->toBeHidden();
    }

    public function testDrawer(): void
    {
        $page = $this->visitComponent('drawer');

        $page->getByRole('button', ['name' => 'Open drawer'])->click();
        $drawer = $page->getByRole('dialog', ['name' => 'Drawer']);
        expect($drawer)->toBeVisible();
        expect($drawer)->toHaveAttribute('data-swipe-direction', 'right');

        $drawer->getByRole('button', ['name' => 'Close'])->click();
        expect($drawer)->toBeHidden();
    }

    public function testPopover(): void
    {
        $page = $this->visitComponent('popover');

        $trigger = $page->getByRole('button', ['name' => 'Notifications']);
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
        $page->getByRole('button', ['name' => 'Notifications'])->click();
        expect($page->getByRole('dialog', ['name' => 'Notifications']))->toBeVisible();
    }

    public function testPreviewCardOpensOnHover(): void
    {
        $page = $this->visitComponent('preview-card');

        $page->getByRole('link', ['name' => 'typography'])->hover();
        $card = $page->getByText('is the art and science of arranging type');
        expect($card)->toBeVisible();

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
        $detached = $page->getByRole('region', ['name' => 'Detached triggers']);
        $delete = $detached->getByRole('button', ['name' => 'Delete']);
        $popup = $page->locator('#delete-tooltip [data-phem-tooltip-target="popup"]');
        $delete->hover();
        expect($popup)->toBeVisible();
        expect($popup)->toHaveText('Delete');
        expect($delete)->toHaveAttribute('data-popup-open', '');
        $page->mouse()->move(5, 5);
        expect($popup)->toBeHidden();

        // Multiple triggers: the payload of the trigger shows in the viewport.
        $multiple = $page->getByRole('region', ['name' => 'Multiple triggers']);
        $popup = $page->locator('#multiple-tooltip [data-phem-tooltip-target="popup"]');
        $multiple->getByRole('button', ['name' => 'Trigger 2'])->hover();
        expect($popup)->toHaveText('Tooltip opened by Trigger 2');
        $multiple->getByRole('button', ['name' => 'Trigger 1'])->hover();
        expect($popup->locator('[data-current]'))->toHaveText('Tooltip opened by Trigger 1');
        $page->mouse()->move(5, 5);
        expect($popup)->toBeHidden();

        // Controlled mode: the button opens the tooltip on the second trigger.
        $controlled = $page->getByRole('region', ['name' => 'Controlled mode with multiple triggers']);
        $controlled->getByRole('button', ['name' => 'Open programmatically'])->click();
        $popup = $controlled->locator('[data-phem-tooltip-target="popup"]');
        expect($popup)->toBeVisible();
        expect($controlled->locator('#trigger-2'))->toHaveAttribute('data-popup-open', '');
        $box = $popup->boundingBox();
        $trigger = $controlled->locator('#trigger-2')->boundingBox();
        self::assertEqualsWithDelta($trigger['x'] + $trigger['width'] / 2, $box['x'] + $box['width'] / 2, 1.5);

        // Animating: the popup moves to the next trigger, and its content changes with an animation.
        $animated = $page->getByRole('region', ['name' => 'Animating the Tooltip']);
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
}
