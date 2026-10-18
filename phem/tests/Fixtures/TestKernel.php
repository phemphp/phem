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

use Phem\PhemBundle;
use PHPUnit\Framework\TestCase;
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

    private static bool $built = false;

    /** Compiles tests/Fixtures/app with phemc, once per test run. */
    public static function compileSources(): void
    {
        if (self::$built) {
            return;
        }
        $dir = __DIR__.'/app';
        $phemc = getenv('PHEMC_BIN') ?: trim((string) shell_exec('command -v phemc'));
        if ('' === $phemc) {
            TestCase::markTestSkipped('phemc is not installed (build it with cargo, or set PHEMC_BIN).');
        }
        exec(\sprintf('cd %s && %s build src --no-warmup 2>&1', escapeshellarg($dir), escapeshellarg($phemc)), $out, $code);
        if (0 !== $code) {
            TestCase::fail("phemc build failed:\n".implode("\n", $out));
        }
        exec('rm -rf '.escapeshellarg($dir.'/var/cache/test'));
        self::$built = true;
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new StimulusBundle(), new PhemBundle()];
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
            ->set(Announcer::class)->autowire()
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
        $routes->import('.', 'phem');
    }
}
