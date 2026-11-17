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
 * The `phem-slider` controller: the positions, the drag with the mouse, the keys and the range.
 */
final class SliderTest extends E2eTestCase
{
    public function testTheThumbAndTheIndicatorShowTheValue(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        expect($page->getByRole('slider', ['name' => 'Volume']))->toHaveValue('25');
        expect($page->locator('#volume-value'))->toHaveText('25');

        // The center of the thumb is at 25% of the 200 px control, and the indicator ends there.
        $control = $page->locator('#volume-control')->boundingBox();
        $thumb = $page->locator('#volume-thumb')->boundingBox();
        $indicator = $page->locator('#volume-indicator')->boundingBox();
        self::assertEqualsWithDelta($control['x'] + 50, $thumb['x'] + $thumb['width'] / 2, 1);
        self::assertEqualsWithDelta(50, $indicator['width'], 1);
        self::assertEqualsWithDelta($control['x'], $indicator['x'], 1);
    }

    public function testADragMovesTheThumb(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        $control = $page->locator('#volume-control')->boundingBox();
        $y = $control['y'] + $control['height'] / 2;
        $mouse = $page->mouse();

        // A press on the track moves the thumb to the point.
        $mouse->move($control['x'] + 100, $y);
        $mouse->down();
        expect($page->locator('#volume'))->toHaveAttribute('data-phem-slider-value-value', '50');
        expect($page->locator('#volume-thumb'))->toHaveAttribute('data-dragging', '');
        expect($page->locator('#volume-input'))->toBeFocused();

        // The pointer capture keeps the drag out of the control.
        $mouse->move($control['x'] + 150, $y + 100, ['steps' => 5]);
        expect($page->locator('#volume-input'))->toHaveValue('75');
        $mouse->move($control['x'] + 400, $y, ['steps' => 5]);
        $mouse->up();

        expect($page->locator('#volume-value'))->toHaveText('100');
        expect($page->locator('#volume-thumb'))->not()->toHaveAttribute('data-dragging', '');
        self::assertSame('volume=100&min=20&max=60', $this->formData($page));
    }

    public function testTheKeysOfAThumbChangeTheValue(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        $input = $page->locator('#volume-input');
        $input->focus();
        $page->keyboard()->press('ArrowRight');
        expect($input)->toHaveValue('26');
        $page->keyboard()->press('Shift+ArrowRight');
        expect($input)->toHaveValue('36');
        $page->keyboard()->press('End');
        expect($input)->toHaveAttribute('aria-valuenow', '100');
        $page->keyboard()->press('PageDown');
        expect($page->locator('#volume-value'))->toHaveText('90');
    }

    public function testTheThumbsOfARangeDoNotCross(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        expect($page->locator('#price-value'))->toHaveText('20 – 60');
        expect($page->locator('#price-min-input'))->toHaveAttribute('aria-valuetext', '20 start range');

        // The keys stop 10 steps before the other thumb.
        $page->locator('#price-min-input')->focus();
        $page->keyboard()->press('End');
        expect($page->locator('#price-min-input'))->toHaveValue('50');

        // A drag of the first thumb pushes the second thumb.
        $thumb = $page->locator('#price-min')->boundingBox();
        $control = $page->locator('#price-control')->boundingBox();
        $mouse = $page->mouse();
        $mouse->move($thumb['x'] + $thumb['width'] / 2, $thumb['y'] + $thumb['height'] / 2);
        $mouse->down();
        $mouse->move($control['x'] + 160, $thumb['y'], ['steps' => 10]);
        $mouse->up();

        expect($page->locator('#price'))->toHaveAttribute('data-phem-slider-value-value', '[80,90]');
        expect($page->locator('#price-value'))->toHaveText('80 – 90');
        $indicator = $page->locator('#price-indicator')->boundingBox();
        self::assertEqualsWithDelta($control['x'] + 160, $indicator['x'], 1);
        self::assertEqualsWithDelta(20, $indicator['width'], 1);
    }

    public function testTheValuesStayAfterAnEvent(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        $page->locator('#price-max-input')->focus();
        $page->keyboard()->press('ArrowRight');
        $page->locator('#volume-input')->focus();
        $page->keyboard()->press('ArrowLeft');
        $page->locator('#save')->click();
        expect($page->locator('#saves'))->toHaveText('1');

        expect($page->locator('#price-max-input'))->toHaveValue('61');
        expect($page->locator('#price-value'))->toHaveText('20 – 61');
        expect($page->locator('#volume-input'))->toHaveValue('24');
        expect($page->getByRole('slider', ['name' => 'Volume']))->toHaveAttribute('aria-valuenow', '24');
        self::assertSame('volume=24&min=20&max=61', $this->formData($page));
        self::assertSame('24%', $page->evaluate("document.getElementById('volume-thumb').style.insetInlineStart"));
    }

    public function testAMousePressShowsNoFocusRing(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        $control = $page->locator('#volume-control')->boundingBox();
        $page->mouse()->click($control['x'] + 100, $control['y'] + $control['height'] / 2);
        expect($page->locator('#volume-input'))->toBeFocused();
        self::assertFalse($page->evaluate("document.getElementById('volume-input').matches(':focus-visible')"));

        // A focus with the keyboard shows the focus ring.
        $page->keyboard()->press('Tab');
        expect($page->locator('#price-min-input'))->toBeFocused();
        $page->keyboard()->press('Shift+Tab');
        expect($page->locator('#volume-input'))->toBeFocused();
        self::assertTrue($page->evaluate("document.getElementById('volume-input').matches(':focus-visible')"));
    }

    public function testTheThumbOfThePressIsOnTop(): void
    {
        $page = $this->visit('/slider');
        self::assertResponseIsSuccessful();

        $mouse = $page->mouse();

        // One thumb is on top only during the press.
        $control = $page->locator('#volume-control')->boundingBox();
        $mouse->move($control['x'] + 100, $control['y'] + $control['height'] / 2);
        $mouse->down();
        expect($page->locator('#volume-thumb'))->toHaveCSS('z-index', '1');
        $mouse->up();
        expect($page->locator('#volume-thumb'))->toHaveCSS('z-index', 'auto');

        // In a range, the thumb of the press is over the thumb of the last change.
        $thumb = $page->locator('#price-min')->boundingBox();
        $mouse->move($thumb['x'] + $thumb['width'] / 2, $thumb['y'] + $thumb['height'] / 2);
        $mouse->down();
        expect($page->locator('#price-min'))->toHaveCSS('z-index', '2');
        $mouse->up();
        expect($page->locator('#price-min'))->toHaveCSS('z-index', '1');
        expect($page->locator('#price-max'))->toHaveCSS('z-index', 'auto');
    }

    private function formData(PageInterface $page): string
    {
        return $page->evaluate("new URLSearchParams(new FormData(document.getElementById('form'))).toString()");
    }
}
