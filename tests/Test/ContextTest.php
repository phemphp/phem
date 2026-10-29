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

use Phem\Binding\ContextError;
use Phem\Test\InteractsWithPhem;
use Phem\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The contexts of the /context page (see Phem\Rendering\ContextResolver).
 */
final class ContextTest extends KernelTestCase
{
    use InteractsWithPhem;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheConsumersReadTheNearestProvider(): void
    {
        $this->visit('/context')
            ->assertSelectorExists('#tab-general[aria-selected="true"]')
            ->assertSelectorExists('#tab-security[aria-selected="false"]')
            ->assertSee('Next from general')
            ->assertSelectorExists('.badge-light')
            ->assertSelectorTextContains('.badge-light', 'Inside');
    }

    public function testMountReadsTheContext(): void
    {
        $this->visit('/context')
            ->assertSelectorExists('#tab-security[data-first="general"]')
            ->click('#tab-security')
            ->assertSelectorExists('#tab-security[data-first="general"]');
    }

    public function testAChangeOfTheProviderRendersAllItsConsumers(): void
    {
        // The handler of a consumer calls the provider: the other consumers show the change too,
        // also under <Card>, which gets the same props.
        $this->visit('/context')
            ->click('#tab-security')
            ->assertSelectorExists('#tab-security[aria-selected="true"]')
            ->assertSelectorExists('#tab-general[aria-selected="false"]')
            ->assertSee('Next from security')
            ->assertState('active', 'security', '#next');
    }

    public function testAMemberPathIsAHandlerOfTheProvider(): void
    {
        $this->visit('/context')
            ->click('#next')
            ->assertSee('Next from security')
            ->assertSelectorExists('#tab-security[aria-selected="true"]')
            ->click('#next')
            ->assertSee('Next from general');
    }

    public function testAnEnumContextChangesOnlyItsSubtree(): void
    {
        $this->visit('/context')
            ->click('#toggle')
            ->assertSelectorTextContains('.badge-dark', 'Inside')
            ->assertSelectorTextContains('.badge-light', 'Outside');
    }

    public function testAChangeOfTheProviderRendersTheConsumersInTheMarkupValuesOfItsSlot(): void
    {
        // The provider renders alone, and its slot prints the markup values of the page again: their
        // consumers render in the new pass, and stay in the shape for the next event.
        $this->visit('/context-values')
            ->assertSelectorCount(3, '.badge-light')
            ->click('#toggle')
            ->assertSelectorCount(3, '.badge-dark')
            ->assertSelectorCount(0, '.badge-light')
            ->click('#toggle')
            ->assertSelectorCount(3, '.badge-light')
            ->assertSee('Alone')
            ->assertSee('Second');
    }

    public function testARequiredContextWithNoProviderThrows(): void
    {
        try {
            $this->visit('/context-missing');
            self::fail('The render must throw.');
        } catch (\Throwable $e) {
            // Twig wraps the exception of a component in a template. <Document> is a template
            // component: it has no fiber, so it is not in the path.
            self::assertInstanceOf(ContextError::class, $e->getPrevious() ?? $e);
            self::assertStringContainsString('<TabsNav> needs a "App\Page\Tabs" context for "tabs", but no component above it provides one.', $e->getMessage());
            self::assertStringContainsString('Path: <ContextMissingPage> > <Root> > <Card> > <TabsNav>.', $e->getMessage());
        }
    }
}
