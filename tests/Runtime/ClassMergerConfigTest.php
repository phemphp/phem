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

namespace Phem\Tests\Runtime;

use Phem\Dom\TailwindClassMerger;
use Phem\Dom\UniqueClassMerger;
use Phem\PhemBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use TalesFromADev\TailwindMerge\TailwindMerge;

final class ClassMergerConfigTest extends TestCase
{
    public function testTheDefaultMergerRemovesTheDuplicates(): void
    {
        $container = self::load([]);

        self::assertSame('phem.class_merger.unique', (string) $container->getAlias('phem.class_merger'));
        self::assertSame(UniqueClassMerger::class, $container->getDefinition('phem.class_merger.unique')->getClass());
        self::assertEquals(new Reference('phem.class_merger'), $container->getDefinition('phem.class_attribute')->getArgument(0));
    }

    public function testTheTailwindMerger(): void
    {
        $container = self::load(['class_merger' => ['tailwind' => ['prefix' => 'tw', 'cache' => 'app.psr16_cache']]]);

        self::assertSame('phem.class_merger.tailwind', (string) $container->getAlias('phem.class_merger'));
        self::assertSame(TailwindClassMerger::class, $container->getDefinition('phem.class_merger.tailwind')->getClass());

        $tailwind = $container->getDefinition('phem.class_merger.tailwind.merge');
        self::assertSame(TailwindMerge::class, $tailwind->getClass());
        self::assertSame(['prefix' => 'tw'], $tailwind->getArgument(0));
        self::assertEquals(new Reference('app.psr16_cache'), $tailwind->getArgument(1));
    }

    public function testTheTailwindMergerWithNoOption(): void
    {
        $container = self::load(['class_merger' => ['tailwind' => true]]);

        $tailwind = $container->getDefinition('phem.class_merger.tailwind.merge');
        self::assertSame([], $tailwind->getArgument(0));
        self::assertNull($tailwind->getArgument(1));
    }

    public function testTheTailwindMergerTakesTheTheme(): void
    {
        $container = self::load(['class_merger' => ['tailwind' => ['theme' => ['text' => ['caption', 'price-lg'], 'font-weight' => ['heavy']]]]]);

        self::assertSame(['theme' => ['text' => ['caption', 'price-lg'], 'font-weight' => ['heavy']]], $container->getDefinition('phem.class_merger.tailwind.merge')->getArgument(0));
    }

    public function testTheTailwindMergerReadsTheThemeOfTheCss(): void
    {
        $css = __DIR__.'/../Fixtures/tailwind/app.css';
        $container = self::load(['class_merger' => ['tailwind' => ['prefix' => 'tw', 'css' => $css, 'theme' => ['text' => ['label'], 'spacing' => []]]]]);

        self::assertSame(['prefix' => 'tw', 'theme' => [
            'font-weight' => ['heavy'],
            'text' => ['label'],
            'text-shadow' => ['glow'],
            'animate' => ['wiggle'],
            'radius' => ['card'],
        ]], $container->getDefinition('phem.class_merger.tailwind.merge')->getArgument(0), 'the names of the configuration replace the names of the CSS, and an empty list removes them');

        $resources = array_map(strval(...), $container->getResources());
        self::assertContains(realpath($css), $resources, 'a change of the CSS rebuilds the container');
        self::assertContains(realpath(__DIR__.'/../Fixtures/tailwind/theme.css'), $resources, 'a change of an import rebuilds the container');
    }

    public function testTheCssOfTheTailwindMergerMustExist(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid configuration for path "phem.class_merger.tailwind.css": The CSS file "assets/styles/missing.css" does not exist.');

        self::load(['class_merger' => ['tailwind' => ['css' => 'assets/styles/missing.css']]]);
    }

    public function testAnUnknownThemeKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The theme key "txt" is not a key of tailwind-merge.');

        self::load(['class_merger' => ['tailwind' => ['theme' => ['txt' => ['caption']]]]]);
    }

    public function testTheMergerOfTheApplication(): void
    {
        $container = self::load(['class_merger' => ['service' => 'app.class_merger']]);

        self::assertSame('app.class_merger', (string) $container->getAlias('phem.class_merger'));
    }

    public function testOneMergerOnly(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Configure phem.class_merger.tailwind or phem.class_merger.service, not both.');

        self::load(['class_merger' => ['tailwind' => true, 'service' => 'app.class_merger']]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.bundles', []);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $extension = (new PhemBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
