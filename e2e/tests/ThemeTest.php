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
 * `theme_script()` sets the theme before the first paint, and `phem-theme` stores the choice of
 * the user in `localStorage`.
 */
final class ThemeTest extends E2eTestCase
{
    /** Keeps the theme of `<html>` when the parser adds `<body>`: before the controllers run. */
    private const FIRST_PAINT_SCRIPT = <<<'JS'
        new MutationObserver((records, observer) => {
            if (!document.body) return
            window.firstPaintTheme = document.documentElement.dataset.theme ?? null
            observer.disconnect()
        }).observe(document, { childList: true, subtree: true })
        JS;

    public function testKeepsTheChoiceOfTheUserBeforeTheFirstPaint(): void
    {
        // Its own browser: the stored choice, the init script and the media stay in this test.
        $page = $this->visitInNewBrowser('/theme');
        self::assertResponseIsSuccessful();
        $page->addInitScript(self::FIRST_PAINT_SCRIPT);
        $page->emulateMedia(['colorScheme' => 'dark']);
        $page->reload();

        // No choice: the theme of the system, before the first paint.
        $html = $page->locator('html');
        expect($html)->toHaveAttribute('data-theme', 'dark');
        self::assertSame('dark', $page->evaluate('window.firstPaintTheme'));
        expect($page->locator('#system'))->toHaveAttribute('aria-pressed', 'true');
        self::assertSame('system', $page->evaluate('document.documentElement.dataset.themeDefault'));

        // The system changes.
        $page->emulateMedia(['colorScheme' => 'light']);
        expect($html)->toHaveAttribute('data-theme', 'light');

        // The user chooses the dark theme: it does not follow the system.
        $page->locator('#dark')->click();
        expect($html)->toHaveAttribute('data-theme', 'dark');
        expect($html)->toHaveCSS('color-scheme', 'dark');
        expect($page->locator('#dark'))->toHaveAttribute('aria-pressed', 'true');
        expect($page->locator('#system'))->toHaveAttribute('aria-pressed', 'false');

        // An event: the server renders the switch again, and the marks stay.
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveExactText('1');
        expect($page->locator('#dark'))->toHaveAttribute('aria-pressed', 'true');

        // A reload sets the stored choice before the first paint.
        $page->reload();
        expect($html)->toHaveAttribute('data-theme', 'dark');
        self::assertSame('dark', $page->evaluate('window.firstPaintTheme'));

        // The toggle stores the other theme, and the switch gets it.
        $page->locator('#toggle')->click();
        expect($html)->toHaveAttribute('data-theme', 'light');
        expect($page->locator('#light'))->toHaveAttribute('aria-pressed', 'true');
        self::assertSame('light', $page->evaluate("localStorage.getItem('phem-theme')"));
    }
}
