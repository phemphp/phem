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

namespace Phem\Test;

use Phem\PhemBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\StimulusBundle\StimulusBundle;

/**
 * A Symfony application to test the `.phpm` components of a Composer package.
 *
 * The test application is in `tests/app/src`: its pages and components use the components of
 * the package. When the kernel boots, it compiles the test application and the package with
 * phemc, one time in each test run. The project dir is the Symfony default: the directory of
 * the `composer.json` above the file of the kernel, so for a package it is the root of the
 * package.
 *
 *     // tests/Kernel.php
 *     final class Kernel extends \Phem\Test\TestKernel
 *     {
 *     }
 *
 *     <!-- phpunit.xml.dist -->
 *     <php>
 *         <server name="KERNEL_CLASS" value="Acme\Ui\Tests\Kernel"/>
 *     </php>
 *
 * Use InteractsWithPhem in a KernelTestCase to mount the components and visit the pages.
 * Override sourceDir() for another directory of the test application, and buildDir() for
 * another directory of the phemc output (it is also the `phem.build_dir` option). To add bundles,
 * configuration or routes, override registerBundles(), configureContainer() or
 * configureRoutes(), and call the parent method.
 *
 * phemc is the PHEMC_BIN environment variable, `vendor/bin/phemc` of the project, or `phemc` in
 * the PATH. Without phemc, the test is skipped.
 */
abstract class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var array<string, true> the project dirs that phemc compiled in this process */
    private static array $built = [];

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new StimulusBundle(), new PhemBundle()];
    }

    public function boot(): void
    {
        $this->compileSources();

        parent::boot();
    }

    /** The `.phpm` sources of the test application, relative to the project dir. */
    protected function sourceDir(): string
    {
        return 'tests/app/src';
    }

    /** The phemc output (`phemc build --out`, and the `phem.build_dir` option), relative to the project dir. */
    protected function buildDir(): string
    {
        return 'var/phem';
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
        $container->extension('phem', ['build_dir' => '%kernel.project_dir%/'.$this->buildDir()]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }

    /** Compiles the project with phemc, one time in each process. */
    private function compileSources(): void
    {
        $projectDir = $this->getProjectDir();
        if (isset(self::$built[$projectDir])) {
            return;
        }

        $phemc = $this->phemc($projectDir);
        if (null === $phemc) {
            TestCase::markTestSkipped('phemc is not installed (install phem/phem, build phemc with cargo, or set PHEMC_BIN).');
        }

        exec(\sprintf('cd %s && %s build %s --out %s --no-warmup 2>&1', escapeshellarg($projectDir), escapeshellarg($phemc), escapeshellarg($this->sourceDir()), escapeshellarg($this->buildDir())), $out, $code);
        if (0 !== $code) {
            TestCase::fail("phemc build failed:\n".implode("\n", $out));
        }

        // The cache of all the environments: the phemc output is in var/phem.
        (new Filesystem())->remove($projectDir.'/var/cache');
        self::$built[$projectDir] = true;
    }

    private function phemc(string $projectDir): ?string
    {
        if ('' !== $bin = (string) getenv('PHEMC_BIN')) {
            return $bin;
        }

        if (is_file($projectDir.'/vendor/bin/phemc')) {
            return $projectDir.'/vendor/bin/phemc';
        }

        $bin = trim((string) shell_exec('command -v phemc'));

        return '' !== $bin ? $bin : null;
    }
}
