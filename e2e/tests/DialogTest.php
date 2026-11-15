<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests;

use function Playwright\Testing\expect;

/**
 * The `phem-dialog` controller: an alert dialog, a dialog that is not a modal, a viewport with a
 * backdrop, and a drawer with snap points.
 */
final class DialogTest extends E2eTestCase
{
    public function testAnAlertDialogClosesOnEscapeOnly(): void
    {
        $page = $this->visit('/dialog');
        self::assertResponseIsSuccessful();

        $page->locator('#alert-open')->click();
        expect($page->locator('#alert-dialog'))->toBeVisible();
        $page->mouse()->click(5, 5);
        expect($page->locator('#alert-dialog'))->toBeVisible();

        $page->keyboard()->press('Escape');
        expect($page->locator('#alert-dialog'))->toBeHidden();
        expect($page->locator('#alert'))->toHaveAttribute('data-phem-dialog-open-value', 'false');
    }

    public function testAModalDialogLocksTheScrollOfThePage(): void
    {
        $page = $this->visit('/dialog');
        self::assertResponseIsSuccessful();

        $overflow = 'getComputedStyle(document.documentElement).overflowY';
        self::assertNotSame('hidden', $page->evaluate($overflow));

        $page->locator('#alert-open')->click();
        expect($page->locator('#alert-dialog'))->toBeVisible();
        self::assertSame('hidden', $page->evaluate($overflow));

        $page->keyboard()->press('Escape');
        expect($page->locator('#alert-dialog'))->toBeHidden();
        self::assertNotSame('hidden', $page->evaluate($overflow));

        // A dialog that is not a modal does not lock the page.
        $page->locator('#plain-open')->click();
        expect($page->locator('#plain-dialog'))->toBeVisible();
        self::assertNotSame('hidden', $page->evaluate($overflow));
    }

    public function testADialogThatIsNotAModalLetsThePageWork(): void
    {
        $page = $this->visit('/dialog');
        self::assertResponseIsSuccessful();

        $page->locator('#plain-open')->click();
        expect($page->locator('#plain-dialog'))->toBeVisible();
        self::assertFalse($page->evaluate("document.getElementById('plain-dialog').matches(':modal')"));

        // A press on the page works, and closes the dialog.
        $page->locator('#page-button')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#plain-dialog'))->toBeHidden();
    }

    public function testAViewportClosesOnAPressOutsideItsPopup(): void
    {
        $page = $this->visit('/dialog');
        self::assertResponseIsSuccessful();

        $page->locator('#framed-open')->click();
        expect($page->locator('#viewport-popup'))->toHaveAttribute('data-open', '');
        expect($page->locator('#backdrop'))->toHaveAttribute('data-open', '');
        expect($page->getByRole('dialog', ['name' => 'Framed']))->toBeVisible();

        $page->locator('#framed-save')->click();
        expect($page->locator('#saves'))->toHaveText('1');
        expect($page->locator('#viewport-popup'))->toBeVisible();

        $page->mouse()->click(5, 5);
        expect($page->locator('#viewport'))->toBeHidden();
        expect($page->locator('#backdrop'))->toBeHidden();
    }

    public function testADrawerStopsAtItsSnapPoints(): void
    {
        $page = $this->visit('/dialog');
        self::assertResponseIsSuccessful();

        $page->locator('#drawer-open')->click();
        $sheet = $page->locator('#sheet');
        expect($sheet)->toHaveAttribute('data-swipe-direction', 'down');
        expect($sheet)->toHaveCSS('--drawer-snap-point-offset', '300px');

        // A slow drag up from the visible part: the full height.
        $box = $page->locator('#sheet-title')->boundingBox();
        $page->mouse()->move($box['x'] + 5, $box['y'] + 5);
        $page->mouse()->down();
        $page->mouse()->move($box['x'] + 5, $box['y'] - 150, ['steps' => 15]);
        $page->mouse()->move($box['x'] + 5, $box['y'] - 280, ['steps' => 15]);
        $page->mouse()->up();
        expect($sheet)->toHaveAttribute('data-expanded', '');
        expect($page->locator('#drawer'))->toHaveAttribute('data-phem-dialog-snap-point-value', '1');
    }
}
