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
 * The client-side helpers of a form: `phem-count`, `phem-reveal`, `phem-checkbox-group`,
 * `phem-depends`, `phem-accordion` and `phem-time`. Each one changes the page with no round
 * trip, and keeps its state through the morph of an event.
 */
final class FormHelpersTest extends E2eTestCase
{
    public function testHelpersChangeThePageWithNoRoundTripAndKeepTheirStateAfterAnEvent(): void
    {
        $page = $this->visit('/form-helpers');
        self::assertResponseIsSuccessful();

        // phem-count: the count of the characters.
        $page->locator('#bio-field')->fill('Hello');
        expect($page->locator('#bio-count'))->toHaveExactText('5/20');

        // phem-reveal: the password shows as a text field.
        $page->locator('#password-field')->fill('secret123');
        $page->locator('#password-show')->click();
        expect($page->locator('#password-field'))->toHaveAttribute('type', 'text');
        expect($page->locator('#password-show'))->toHaveAttribute('aria-pressed', 'true');

        // phem-checkbox-group: the parent checks all the rows, and phem-count counts them.
        $page->locator('#row-2')->check();
        self::assertTrue($page->evaluate("document.getElementById('all').indeterminate"));
        $page->locator('#all')->click();
        expect($page->locator('#row-1'))->toBeChecked();
        expect($page->locator('#row-3'))->toBeChecked();
        expect($page->locator('#rows-count'))->toHaveExactText('3 selected');

        // phem-depends: "Other" shows the text field.
        expect($page->locator('#reason-other'))->toBeHidden();
        expect($page->locator('#reason-text'))->toBeDisabled();
        $page->locator('#reason-field')->selectOption('other');
        expect($page->locator('#reason-other'))->toBeVisible();
        expect($page->locator('#reason-text'))->toBeEnabled();

        // phem-accordion: one item at a time.
        $page->locator('#faq-shipping-trigger')->click();
        expect($page->locator('#faq-shipping-panel'))->toBeVisible();
        $page->locator('#faq-returns-trigger')->click();
        expect($page->locator('#faq-returns-panel'))->toBeVisible();
        expect($page->locator('#faq-shipping-panel'))->toBeHidden();

        // phem-time: a relative time in the language of the page.
        expect($page->locator('#time'))->toContainText('years ago');

        // An event: the server renders all the helpers again.
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveExactText('1');
        expect($page->locator('#selected'))->toHaveExactText('1,2,3');

        expect($page->locator('#bio-count'))->toHaveExactText('5/20');
        expect($page->locator('#password-field'))->toHaveAttribute('type', 'text');
        expect($page->locator('#all'))->toBeChecked();
        expect($page->locator('#rows-count'))->toHaveExactText('3 selected');
        expect($page->locator('#reason-other'))->toBeVisible();
        expect($page->locator('#reason-text'))->toBeEnabled();
        expect($page->locator('#faq-returns-panel'))->toBeVisible();
        expect($page->locator('#faq-shipping-panel'))->toBeHidden();
        expect($page->locator('#time'))->toContainText('years ago');
    }
}
