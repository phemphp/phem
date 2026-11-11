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
 * `phem-scrollspy` marks the link of the section in view, with the real layout of the page: the
 * `scroll-margin-top` of the sections, a click on a link and the end of the scroll.
 */
final class ScrollSpyTest extends E2eTestCase
{
    public function testMarksTheLinkOfTheSectionInViewAndKeepsItAfterAnEvent(): void
    {
        $page = $this->visit('/scrollspy');
        self::assertResponseIsSuccessful();

        // Above the first section: no link is current.
        expect($page->locator('#toc [aria-current]'))->toHaveCount(0);

        // A scroll puts "Install" at the line.
        $page->evaluate("window.scrollTo(0, document.getElementById('install').offsetTop - 80)");
        expect($page->locator('#to-install'))->toHaveAttribute('aria-current', 'true');

        // A click on a link scrolls its section to its scroll margin, and marks it.
        $page->locator('#to-configure')->click();
        expect($page->locator('#to-configure'))->toHaveAttribute('aria-current', 'true');
        expect($page->locator('#to-configure'))->toHaveAttribute('data-active', '');
        expect($page->locator('#to-install'))->not()->toHaveAttribute('aria-current', 'true');

        // The end of the scroll marks the short last section.
        $page->evaluate('window.scrollTo(0, document.documentElement.scrollHeight)');
        expect($page->locator('#to-deploy'))->toHaveAttribute('aria-current', 'true');
        expect($page->locator('#toc [aria-current]'))->toHaveCount(1);

        // An event: the server renders the links again, and the mark stays.
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveExactText('1');
        expect($page->locator('#to-deploy'))->toHaveAttribute('aria-current', 'true');
        expect($page->locator('#to-deploy'))->toHaveAttribute('data-active', '');
    }
}
