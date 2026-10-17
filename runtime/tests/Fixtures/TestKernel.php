<?php

declare(strict_types=1);

namespace Mamu\Tests\Fixtures;

use Mamu\MamuBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\StimulusBundle\StimulusBundle;

/**
 * A Symfony application for the fixture project in tests/Fixtures/app.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new StimulusBundle(), new MamuBundle()];
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
            ->set(AccessListener::class)
                ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 8])
                ->tag('kernel.event_listener', ['event' => 'kernel.controller_arguments', 'method' => 'onKernelControllerArguments'])
            ->set(ScientistFinder::class)
            // The #[Path] entity props of the fixture pages get their entities from ScientistFinder.
            ->alias('mamu.entity_finder', ScientistFinder::class)
            ->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mamu');
    }
}
