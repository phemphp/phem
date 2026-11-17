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
 * The `phem-scroll-area` controller: custom scrollbars over a native scroll container.
 */
final class ScrollAreaTest extends E2eTestCase
{
    public function testTheScrollbarsShowTheOverflow(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        expect($page->locator('#area'))->toHaveAttribute('data-has-overflow-y', '');
        expect($page->locator('#area'))->toHaveAttribute('data-overflow-y-end', '');
        expect($page->locator('#area'))->not()->toHaveAttribute('data-overflow-y-start', '');
        expect($page->locator('#vbar'))->toBeVisible();
        expect($page->locator('#hbar'))->toBeHidden();
        expect($page->locator('#corner'))->toBeHidden();

        // The viewport is 100px of 500px: the thumb is 20px of the 100px track.
        self::assertSame('20px', $page->evaluate("document.getElementById('vbar').style.getPropertyValue('--scroll-area-thumb-height')"));
        self::assertSame(20, (int) $page->locator('#vthumb')->boundingBox()['height']);
        self::assertSame(0, (int) $page->evaluate("document.getElementById('viewport').offsetWidth - document.getElementById('viewport').clientWidth"));
    }

    public function testAScrollMovesTheThumbAndMarksTheEdges(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        $page->locator('#viewport')->hover();
        expect($page->locator('#vbar'))->toHaveAttribute('data-hovering', '');

        $page->evaluate("document.getElementById('viewport').scrollTop = 200");
        expect($page->locator('#area'))->toHaveAttribute('data-scrolling', '');
        expect($page->locator('#area'))->toHaveAttribute('data-overflow-y-start', '');
        self::assertSame('200px', $page->evaluate("document.getElementById('viewport').style.getPropertyValue('--scroll-area-overflow-y-start')"));
        expect($page->locator('#vthumb'))->toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 40)');

        // The mark of the scroll goes after 500 ms.
        expect($page->locator('#area'))->not()->toHaveAttribute('data-scrolling', '');
    }

    public function testADragOfTheThumbScrollsTheViewport(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        $thumb = $page->locator('#vthumb')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($thumb['x'] + 5, $thumb['y'] + 5);
        $mouse->down();
        // Out of the scroll area: the pointer capture keeps the drag.
        $mouse->move($thumb['x'] + 80, $thumb['y'] + 45, ['steps' => 5]);
        $mouse->up();

        // 40px of the 80px free track: half of the 400px scroll.
        self::assertSame(200, $this->scrollTop($page));
    }

    public function testAPressOnTheTrackScrollsToThePoint(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        $bar = $page->locator('#vbar')->boundingBox();
        $page->mouse()->click($bar['x'] + 5, $bar['y'] + 90);

        // The center of the thumb goes to the pointer, and the end of the track stops it.
        self::assertSame(400, $this->scrollTop($page));
        expect($page->locator('#area'))->not()->toHaveAttribute('data-overflow-y-end', '');
    }

    public function testTheScrollPositionStaysAfterAnEvent(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        $page->evaluate("document.getElementById('viewport').scrollTop = 120");
        expect($page->locator('#area'))->toHaveAttribute('data-overflow-y-start', '');

        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        self::assertSame(120, $this->scrollTop($page));
        expect($page->locator('#area'))->toHaveAttribute('data-overflow-y-start', '');
        expect($page->locator('#vthumb'))->toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 24)');
        expect($page->locator('#hbar'))->toBeHidden();
        expect($page->locator('#vbar'))->toHaveCSS('width', '10px');
    }

    public function testTheScrollMarkGoesAtTheEndOfADrag(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        $thumb = $page->locator('#vthumb')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($thumb['x'] + 5, $thumb['y'] + 5);
        $mouse->down();
        $mouse->move($thumb['x'] + 5, $thumb['y'] + 45, ['steps' => 5]);
        $page->evaluate('new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
        expect($page->locator('#area'))->toHaveAttribute('data-scrolling', '');

        // Read the marks at once: `expect` waits longer than the timer of a scroll.
        $mouse->up();
        $marks = $page->evaluate("['area', 'vthumb'].map((id) => document.getElementById(id).hasAttribute('data-scrolling'))");
        self::assertSame([false, false], $marks);
    }

    public function testTheThumbKeepsTheFractionsOfAPixel(): void
    {
        $page = $this->visit('/scroll-area');
        self::assertResponseIsSuccessful();

        // A content of 300px: the thumb is 100 / 3 px of the 100px track.
        $page->evaluate("(() => { const content = document.getElementById('content'); content.style.height = '300px'; content.style.overflow = 'hidden'; document.getElementById('viewport').scrollTop = 100 })()");
        $page->waitForFunction("() => document.getElementById('vbar').style.getPropertyValue('--scroll-area-thumb-height') !== '20px'");

        $size = (float) $page->evaluate("parseFloat(document.getElementById('vbar').style.getPropertyValue('--scroll-area-thumb-height'))");
        self::assertEqualsWithDelta(100 / 3, $size, 0.01);
    }

    private function scrollTop(PageInterface $page): int
    {
        return (int) round((float) $page->evaluate("document.getElementById('viewport').scrollTop"));
    }
}
