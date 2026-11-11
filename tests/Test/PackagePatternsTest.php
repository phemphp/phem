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
use Phem\Tests\Fixtures\Package\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The patterns of docs/package-authoring.md, with the components of a package (tests/Fixtures/Package):
 * the variants of a component, and the controlled and the uncontrolled state.
 */
final class PackagePatternsTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAVariantGivesItsClasses(): void
    {
        $this->visit('/tabs')
            ->assertSeeHtml('<button class="kit px-4 py-2 border border-blue-500 text-blue-500 w-full">Save</button>');

        $this->mount('Acme\Kit\Button', ['label' => 'Go'])
            ->assertSeeHtml('<button class="kit px-4 py-2 bg-blue-500 text-white" data-phem-c=');
    }

    public function testUncontrolledTabsKeepTheActiveTab(): void
    {
        $page = $this->visit('/tabs')
            ->assertSelectorExists('#uncontrolled [value="security"][aria-selected="true"]');

        $page->click('#uncontrolled [value="general"]')
            ->assertSelectorExists('#uncontrolled [value="general"][aria-selected="true"]')
            ->assertSelectorExists('#uncontrolled [value="security"][aria-selected="false"]');
    }

    public function testControlledTabsShowTheValueOfTheParent(): void
    {
        $page = $this->visit('/tabs')
            ->assertSelectorExists('#controlled [value="general"][aria-selected="true"]');

        $page->click('#controlled [value="security"]')
            ->assertState('tab', 'security')
            ->assertSelectorTextContains('#tab', 'security')
            ->assertSelectorExists('#controlled [value="security"][aria-selected="true"]')
            ->assertSelectorExists('#controlled [value="general"][aria-selected="false"]');
    }
}
