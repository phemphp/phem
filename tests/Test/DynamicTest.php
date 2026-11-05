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

namespace Phem\Tests\Test;

use Phem\Html;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Error\RuntimeError;

/**
 * The `<Dynamic>` elements of the /dynamic page (see Phem\Dom\DynamicTag).
 */
final class DynamicTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAComponentInNestedDynamicElementsKeepsItsFiber(): void
    {
        $page = $this->visit('/dynamic');

        self::assertMatchesRegularExpression('#<section class="btn" id="wrap"><div id="inner"><span id="nested" data-phem-on-click="[^"]+" data-phem-c="phem[0-9a-f]{12}">Count 0</span></div></section>#', $page->html(), 'the id of the child goes on its element, with no <phem-c>');

        $page->click('#nested')
            ->assertStatus(200)
            ->assertSelectorTextContains('#nested', 'Count 1')
            ->assertSelectorTextContains('#counter', 'Count 0')
            ->assertSelectorExists('#wrap > #inner > #nested[data-phem-c]');
    }

    public function testAVoidElementRefusesChildren(): void
    {
        // Twig gives the message of the exception, and the template of the error.
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('<img> is a void element: <Dynamic tag="img"> cannot have children.');

        $this->mount('App\Ui\Dynamic\PolyMedia', ['tag' => 'img', 'children' => new Html('<b>Text</b>')]);
    }

    public function testAVoidElementTakesNoContent(): void
    {
        $this->mount('App\Ui\Dynamic\PolyMedia', ['tag' => 'img', 'children' => Html::empty()])
            ->assertSelectorExists('img[src="/a.png"]');
    }

    public function testATagFromAPropCannotBeAScript(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('<Dynamic> cannot render a <script>: write it as static markup.');

        $this->mount('App\Ui\Dynamic\PolyButton', ['tag' => 'script', 'children' => new Html('alert(1)')]);
    }
}
