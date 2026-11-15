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
 * The default styles of phem.css, and a utility class in a cascade layer that replaces them.
 */
final class StylesTest extends E2eTestCase
{
    public function testAToastPartWithAClassGetsNoDefaultLook(): void
    {
        $page = $this->visit('/styles');
        self::assertResponseIsSuccessful();

        expect($page->locator('#plain'))->toHaveCSS('background-color', 'rgb(21, 128, 61)');
        expect($page->locator('#plain'))->toHaveCSS('border-radius', '6px');
        expect($page->locator('#styled'))->toHaveCSS('background-color', 'rgb(255, 255, 255)');
        expect($page->locator('#styled'))->toHaveCSS('border-radius', '0px');

        expect($page->locator('#plain-limited'))->toBeHidden();
        expect($page->locator('#styled-limited'))->toBeVisible();
    }
}
