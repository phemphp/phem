<?php

declare(strict_types=1);

namespace Rephp\Tests\Fixtures;

use Psr\Log\NullLogger;
use Rephp\RephpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A Symfony application for the fixture project in tests/Fixtures/app.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new RephpBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/app';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->services()
            ->set(Limits::class)->autowire()
            ->set(ScientistFinder::class)
            ->set('logger', NullLogger::class);
    }

    protected function build(ContainerBuilder $container): void
    {
        // The #[Path] entity props of the fixture pages get their entities from ScientistFinder.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('rephp.prop_binder')->setArgument(0, new Reference(ScientistFinder::class));
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'rephp');
    }
}
