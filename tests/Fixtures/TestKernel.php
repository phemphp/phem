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

namespace Phem\Tests\Fixtures;

use Phem\Test\TestKernel as PhemTestKernel;
use Psr\Log\NullLogger;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A Symfony application for the fixture project in tests/Fixtures/app.
 */
final class TestKernel extends PhemTestKernel
{
    public function registerBundles(): iterable
    {
        $bundles = [...parent::registerBundles()];
        if ('profiler' === $this->environment) {
            $bundles[] = new WebProfilerBundle();
        }

        return $bundles;
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/app';
    }

    protected function sourceDir(): string
    {
        return 'src';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('framework', [
            // For the #[Async] handlers of async.phpm: their work goes to a MemoryBus (see TestAsyncPass).
            'messenger' => ['enabled' => true],
            // For the #[RateLimit] page of access.phpm. The storage is in memory, so each test starts with no hits.
            'rate_limiter' => ['phem_events' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute', 'lock_factory' => null, 'storage_service' => InMemoryStorage::class]],
            // For the live validation of validation.phpm. FrameworkBundle does not enable it when symfony/validator is a dev package of the root.
            'validation' => ['enabled' => true],
            // For the live validation of the Symfony form of form.phpm, for the same reason.
            'form' => ['enabled' => true],
            'property_access' => ['enabled' => true],
        ]);
        // TwigBundle does not load the form functions (form_row()) for the same reason.
        $container->import(\dirname((string) (new \ReflectionClass(TwigBundle::class))->getFileName()).'/Resources/config/form.php');

        if ('nostream' === $this->environment) {
            // The browser loads all the <Suspense> boundaries with an event.
            $container->extension('phem', ['suspense_stream' => false]);
        }

        if ('profiler' === $this->environment) {
            $container->extension('framework', ['profiler' => ['enabled' => true, 'collect' => true]]);
            $container->extension('web_profiler', ['toolbar' => false]);
        }

        $container->services()
            ->set(InMemoryStorage::class)
            // FrameworkBundle removes this service when symfony/expression-language is a dev package of the root.
            // The Expression key of a #[RateLimit] in access.phpm needs it.
            ->set('controller.expression_language', ExpressionLanguage::class)
            ->set(Limits::class)->autowire()
            ->set(Announcer::class)->autowire()
            ->set(Guestbook::class)
            ->set(CookieSetter::class)
                ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse'])
            ->set(AccessListener::class)
                ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 8])
                ->tag('kernel.event_listener', ['event' => 'kernel.controller_arguments', 'method' => 'onKernelControllerArguments'])
            ->set(ScientistFinder::class)
            // The #[Path] entity props of the fixture pages get their entities from ScientistFinder.
            ->alias('phem.entity_finder', ScientistFinder::class)
            ->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ('profiler' === $this->environment) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
        }

        parent::configureRoutes($routes);

        // The pages of the fixture package (app/vendor/acme/ui).
        $routes->import('acme/ui', 'phem')->prefix('/ui');
    }
}
