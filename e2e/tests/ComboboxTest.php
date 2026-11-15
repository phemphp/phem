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
 * The `phem-combobox` controller: the server gives the results, and the keyboard chooses one.
 */
final class ComboboxTest extends E2eTestCase
{
    public function testTypeMoveAndChooseAResult(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#input');
        $input->pressSequentially('pa');
        expect($page->locator('#list li'))->toHaveCount(2);
        expect($page->locator('#popup'))->toBeVisible();
        expect($input)->toHaveAttribute('aria-expanded', 'true');

        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveAttribute('aria-activedescendant', 'city-Parma');
        expect($input)->toBeFocused();

        $page->keyboard()->press('Enter');
        expect($page->locator('#city'))->toHaveExactText('Parma');
        expect($input)->toHaveValue('Parma');
        expect($page->locator('#popup'))->toBeHidden();
    }

    public function testNoResultShowsTheEmptyTarget(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $page->locator('#input')->pressSequentially('zz');
        expect($page->locator('#empty'))->toBeVisible();
        expect($page->locator('#popup'))->toHaveAttribute('data-empty', '');

        $page->keyboard()->press('Escape');
        expect($page->locator('#popup'))->toBeHidden();
    }

    public function testTheBrowserFiltersAListOfTheServer(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#fruit-input');
        $input->pressSequentially('ecl');
        expect($page->locator('#fruit-list li:visible'))->toHaveCount(1);
        expect($page->locator('#fruit-4'))->toBeVisible();

        $input->fill('');
        $input->pressSequentially('an');
        expect($page->locator('#fruit-list li:visible'))->toHaveCount(1);
        expect($page->locator('#fruit-2'))->toBeVisible();

        $input->fill('');
        $input->pressSequentially('zz');
        expect($page->locator('#fruit-empty'))->toBeVisible();
        expect($page->locator('#fruit-popup'))->toHaveAttribute('data-empty', '');
    }

    public function testTheBothModeCompletesTheInputAndChoosesAnItem(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#fruit-input');
        $input->pressSequentially('e');
        expect($input)->toHaveAttribute('aria-autocomplete', 'both');

        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveValue('Apple');
        $page->keyboard()->press('ArrowDown');
        expect($input)->toHaveValue('Cherry');

        $page->keyboard()->press('Escape');
        expect($input)->toHaveValue('e');
        expect($page->locator('#fruit-popup'))->toBeHidden();

        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('ArrowDown');
        $page->keyboard()->press('Enter');
        expect($input)->toHaveValue('Cherry');
        expect($page->locator('#fruit-popup'))->toBeHidden();

        // An event of the other field morphs the page: the text stays.
        $page->locator('#input')->pressSequentially('pa');
        expect($page->locator('#list li'))->toHaveCount(2);
        expect($input)->toHaveValue('Cherry');
    }

    public function testAListAndItemsWithNoIdLinkWithElementReferences(): void
    {
        $page = $this->visit('/combobox');
        self::assertResponseIsSuccessful();

        $input = $page->getByRole('combobox', ['name' => 'Color']);
        $input->pressSequentially('re');
        expect($page->locator('#color-popup'))->toBeVisible();
        $controls = "document.getElementById('color-input').ariaControlsElements?.[0]?.classList.contains('color-list') ?? false";
        self::assertTrue($page->evaluate($controls));

        $page->keyboard()->press('ArrowDown');
        $active = "document.getElementById('color-input').ariaActiveDescendantElement?.textContent.trim() ?? null";
        self::assertSame('Green', $page->evaluate($active));
        $page->keyboard()->press('ArrowDown');
        self::assertSame('Red', $page->evaluate($active));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.color-list[id], .color-list li[id]').length"));

        $page->keyboard()->press('Escape');
        self::assertNull($page->evaluate($active));

        // The morph of an event keeps the list with no id, and the link.
        $page->locator('#input')->pressSequentially('ly');
        expect($page->locator('#list li'))->toHaveCount(1);
        self::assertTrue($page->evaluate($controls));
        self::assertSame(0, $page->evaluate("document.querySelectorAll('.color-list[id], .color-list li[id]').length"));
    }
}
