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

use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Components in a `<table>` and in a `<select>`: the fiber id is on their root element.
 */
final class TableTest extends KernelTestCase
{
    use InteractsWithPhem;

    public static function setUpBeforeClass(): void
    {
        TestKernel::compileSources();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheRowsAndTheOptionsHaveNoWrapper(): void
    {
        $this->visit('/table')
            ->assertSelectorCount(3, '#grid > tbody > tr[data-phem-c]')
            ->assertSelectorCount(3, '#pick > option[data-phem-c]')
            ->assertSelectorNotExists('#grid phem-c, #pick phem-c');
    }

    public function testAnEventMorphsTheRowWithItsAttributes(): void
    {
        $this->visit('/table')
            ->click('#click-1')
            ->assertSelectorTextContains('#click-1', '1 clicks')
            ->assertSelectorExists('#grid > tbody > tr#row-1.clicks-1[data-phem-c]')
            ->assertSelectorExists('#grid > tbody > tr#row-0.clicks-0')
            ->assertSelectorCount(3, '#grid > tbody > tr');
    }

    public function testTheKeptRowsStayInTheTable(): void
    {
        $page = $this->visit('/table')->click('#click-2');

        // The page renders, and its rows and options are placeholders.
        $page->click('#bump')
            ->assertSee('Bumps 1')
            ->assertSelectorCount(3, '#grid > tbody > tr[data-phem-c]')
            ->assertSelectorCount(3, '#pick > option[data-phem-c]')
            ->assertSelectorTextContains('#click-2', '1 clicks')
            ->assertSelectorNotExists('template');
    }
}
