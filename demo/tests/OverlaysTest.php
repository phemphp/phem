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
        self::markTestIncomplete('phem-popover links the title only by id, and the function components have no ids (notes/phem-gaps.md, gap 15).');

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

        $page->getByRole('button', ['name' => 'Bold'])->hover();
        expect($page->getByRole('tooltip'))->toHaveText('Bold');

        $page->mouse()->move(5, 5);
        expect($page->getByRole('tooltip'))->toBeHidden();

        $page->getByRole('button', ['name' => 'Italic'])->focus();
        expect($page->getByRole('tooltip'))->toHaveText('Italic');
    }
}
