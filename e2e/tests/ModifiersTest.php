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
 * The key filters, the shortcuts and the event modifiers: `phem:key`, `phem:shortcut`, `phem:stop`
 * and `phem:prevent`.
 */
final class ModifiersTest extends E2eTestCase
{
    public function testKeyFilterSendsOnlyItsKeys(): void
    {
        $page = $this->visit('/modifiers');
        self::assertResponseIsSuccessful();

        $draft = $page->locator('#draft');
        $draft->pressSequentially('Hi');
        $draft->press('Enter');

        // The other keys send no event, and keep their default action: the text and the new line are in the field.
        expect($draft)->toHaveValue("Hi\n");
        self::assertSame('GET', $this->getLastRequest()?->getMethod());

        $draft->press('Control+Enter');
        expect($page->locator('#log'))->toHaveExactText('send Enter');

        $draft->press('Escape');
        expect($page->locator('#log'))->toHaveExactText('send Enter, send Escape');
    }

    public function testStopKeepsTheClickFromTheRow(): void
    {
        $page = $this->visit('/modifiers');
        self::assertResponseIsSuccessful();

        $page->locator('#star')->click();
        expect($page->locator('#log'))->toHaveExactText('star');

        $page->locator('#subject')->click();
        expect($page->locator('#log'))->toHaveExactText('star, open');
    }

    public function testPreventKeepsTheLinkFromOpening(): void
    {
        $page = $this->visit('/modifiers');
        self::assertResponseIsSuccessful();

        $page->locator('#link')->click();
        expect($page->locator('#log'))->toHaveExactText('open');
        self::assertStringEndsWith('/modifiers', $page->url());
    }

    public function testShortcutClicksItsElementFromThePage(): void
    {
        $page = $this->visit('/modifiers');
        self::assertResponseIsSuccessful();

        // `mod` is Ctrl on Linux.
        $page->keyboard()->press('Control+k');
        expect($page->locator('#log'))->toHaveExactText('palette');

        $page->keyboard()->press('j');
        expect($page->locator('#log'))->toHaveExactText('palette, next');
    }

    public function testShortcutFocusesAFieldAndLetsTheUserType(): void
    {
        $page = $this->visit('/modifiers');
        self::assertResponseIsSuccessful();

        // The key does not do its default action: the field gets the focus, and no "/".
        $page->keyboard()->press('/');
        $filter = $page->locator('#filter');
        expect($filter)->toBeFocused();
        expect($filter)->toHaveValue('');

        // In a field, a key with no modifier is text.
        $filter->pressSequentially('j/');
        expect($filter)->toHaveValue('j/');
        self::assertSame('GET', $this->getLastRequest()?->getMethod());

        // A key with a modifier runs.
        $filter->press('Control+k');
        expect($page->locator('#log'))->toHaveExactText('palette');
    }
}
