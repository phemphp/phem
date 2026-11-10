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
}
