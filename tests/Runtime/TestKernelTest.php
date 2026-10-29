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

use Phem\Test\TestKernel;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * How Phem\Test\TestKernel finds phemc: PHEMC_BIN, then `vendor/bin/phemc` of the project, then
 * `phemc` in the PATH. The fake phemc scripts fail, so the build fails with their output.
 */
final class TestKernelTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-test-kernel-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/src', 0o777, true);
        $this->env = ['PHEMC_BIN' => getenv('PHEMC_BIN'), 'PATH' => getenv('PATH')];
        putenv('PHEMC_BIN');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }

        (new Filesystem())->remove($this->dir);
    }

    public function testTheVendorPhemcOfTheProjectBuilds(): void
    {
        $this->script('vendor/bin/phemc', 'vendor phemc');

        self::assertSame("phemc build failed:\nvendor phemc build src --out var/phem --no-warmup", $this->bootFailure());
    }

    public function testPhemcBinComesFirst(): void
    {
        $this->script('vendor/bin/phemc', 'vendor phemc');
        putenv('PHEMC_BIN='.$this->script('bin/phemc', 'env phemc'));

        self::assertSame("phemc build failed:\nenv phemc build src --out var/phem --no-warmup", $this->bootFailure());
    }

    public function testTheBuildGoesToTheBuildDirOfTheKernel(): void
    {
        $this->script('vendor/bin/phemc', 'vendor phemc');

        self::assertSame("phemc build failed:\nvendor phemc build src --out build/phem --no-warmup", $this->bootFailure('build/phem'));
    }

    public function testNoPhemcSkipsTheTest(): void
    {
        putenv('PATH=/nonexistent');

        try {
            $this->kernel()->boot();
            self::fail('The boot needs phemc.');
        } catch (SkippedWithMessageException $e) {
            self::assertStringStartsWith('phemc is not installed', $e->getMessage());
        }
    }

    private function bootFailure(string $buildDir = 'var/phem'): string
    {
        try {
            $this->kernel($buildDir)->boot();
        } catch (AssertionFailedError $e) {
            return $e->getMessage();
        }

        self::fail('The build of the fake phemc fails.');
    }

    /** A script that prints its name and its arguments, and fails. */
    private function script(string $file, string $name): string
    {
        $path = $this->dir.'/'.$file;
        (new Filesystem())->dumpFile($path, "#!/bin/sh\necho \"{$name} \$*\"\nexit 3\n");
        chmod($path, 0o755);

        return $path;
    }

    private function kernel(string $buildDir = 'var/phem'): TestKernel
    {
        return new class('test', false, $this->dir, $buildDir) extends TestKernel {
            public function __construct(string $environment, bool $debug, private readonly string $dir, private readonly string $build)
            {
                parent::__construct($environment, $debug);
            }

            public function getProjectDir(): string
            {
                return $this->dir;
            }

            protected function sourceDir(): string
            {
                return 'src';
            }

            protected function buildDir(): string
            {
                return $this->build;
            }
        };
    }
}
