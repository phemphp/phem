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

use Playwright\Page\PageInterface;

use function Playwright\Testing\expect;

/**
 * The `phem-toast` controller in a real browser: the time after a hover, a swipe, and the order
 * of the toasts.
 */
final class ToastTest extends E2eTestCase
{
    private const TOASTS = '#toasts [data-phem-toast]';

    public function testAHoverKeepsTheTimeThatRemains(): void
    {
        $page = $this->open();
        $this->toast($page, '{ message: "Hello", duration: 2000 }');
        $toast = $page->locator(self::TOASTS);
        expect($toast)->toHaveCount(1);

        $this->wait($page, 1200);
        $box = $toast->boundingBox();
        $page->mouse()->move($box['x'] + $box['width'] / 2, $box['y'] + $box['height'] / 2);
        $this->wait($page, 2500);
        expect($toast)->toHaveCount(1);
        expect($toast)->not()->toHaveAttribute('data-ending-style', '');

        // The time that remained goes on: the full time does not start again.
        $page->evaluate('() => { window.leftAt = performance.now() }');
        $page->mouse()->move(5, 5);
        $page->waitForFunction('() => { const el = document.querySelector("#toasts [data-phem-toast]"); return !el || el.hasAttribute("data-ending-style") }');
        $time = $page->evaluate('() => performance.now() - window.leftAt');

        self::assertLessThan(1400, $time);
    }

    public function testADiagonalSwipeMovesOnOneAxis(): void
    {
        $page = $this->open();
        $this->toast($page, '{ message: "Hello", duration: 0 }');
        $toast = $page->locator(self::TOASTS);
        expect($toast)->toHaveCount(1);

        $box = $toast->boundingBox();
        $x = $box['x'] + $box['width'] / 2;
        $y = $box['y'] + $box['height'] / 2;
        $page->mouse()->move($x, $y);
        $page->mouse()->down();
        $page->mouse()->move($x + 20, $y + 8, ['steps' => 2]);
        $page->mouse()->move($x + 60, $y + 50, ['steps' => 5]);

        $state = $page->evaluate('() => {
            const el = document.querySelector("#toasts [data-phem-toast]")

            return {
                x: el.style.getPropertyValue("--toast-swipe-movement-x"),
                y: el.style.getPropertyValue("--toast-swipe-movement-y"),
                transition: el.style.getPropertyValue("transition"),
                direction: el.getAttribute("data-swipe-direction"),
                translateY: new DOMMatrix(getComputedStyle(el).transform).m42,
            }
        }');
        self::assertSame(['x' => '60px', 'y' => '0px', 'transition' => 'none', 'direction' => 'right', 'translateY' => 0], $state);

        $page->mouse()->up();
        expect($toast)->toHaveCount(0);
    }

    public function testTabGoesToTheNewestToastFirst(): void
    {
        $page = $this->open();
        $page->locator('#export')->click();
        $export = $page->locator(self::TOASTS)->filter(['hasText' => 'Export complete']);
        expect($export)->toHaveAttribute('data-type', 'success');
        $this->toast($page, '"Second"');
        $second = $page->locator(self::TOASTS)->filter(['hasText' => 'Second']);
        expect($second)->toHaveCount(1);

        // The newest toast is first in the DOM, and phem.css shows it at the bottom.
        self::assertGreaterThan($export->boundingBox()['y'], $second->boundingBox()['y']);

        $page->locator('#before')->focus();
        $page->keyboard()->press('F6');
        expect($page->locator('#toasts'))->toBeFocused();

        $page->keyboard()->press('Tab');
        expect($second)->toBeFocused();
        $page->keyboard()->press('Tab');
        $close = $second->locator('[data-phem-toast-close]');
        expect($close)->toBeFocused();
        expect($close)->not()->toHaveAttribute('aria-hidden', 'true');
        $page->keyboard()->press('Tab');
        expect($export)->toBeFocused();
    }

    public function testAClickOnTheCloseButtonDoesNotMoveTheFocusToAToast(): void
    {
        $page = $this->open();
        $this->toast($page, '{ message: "First", duration: 0 }');
        $this->toast($page, '{ message: "Second", duration: 0 }');
        $toasts = $page->locator(self::TOASTS);
        expect($toasts)->toHaveCount(2);

        $page->locator('#toasts')->hover();
        expect($page->locator('#toasts'))->toHaveAttribute('data-expanded', '');
        $toasts->first()->locator('[data-phem-toast-close]')->click();

        expect($toasts)->toHaveCount(1);
        expect($toasts)->toContainText('First');
        self::assertTrue($page->evaluate('() => !document.activeElement.closest("[data-phem-toast]")'));
    }

    private function open(): PageInterface
    {
        $page = $this->visit('/toast');
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function wait(PageInterface $page, int $ms): void
    {
        $page->evaluate('(ms) => new Promise((resolve) => setTimeout(resolve, ms))', $ms);
    }

    private function toast(PageInterface $page, string $detail): void
    {
        $page->evaluate('() => window.dispatchEvent(new CustomEvent("phem:toast", { detail: '.$detail.' }))');
    }
}
