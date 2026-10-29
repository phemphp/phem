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

use Phem\Manifest\Manifest;
use Phem\Manifest\PreloadWarmer;
use PHPUnit\Framework\TestCase;

final class PreloadWarmerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-preload-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/var/phem', recursive: true);
        mkdir($this->dir.'/var/cache/prod', recursive: true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testTheBuildDirectoryGetsAFileThatLoadsTheCompiledCode(): void
    {
        $function = 'phem_preload_'.bin2hex(random_bytes(4));
        file_put_contents($this->dir.'/var/phem/manifest.php', '<?php return [];');
        file_put_contents($this->dir.'/var/phem/autoload.php', "<?php function {$function}(): void {}");

        $files = $this->warmer()->warmUp($this->dir.'/var/cache/prod', $this->dir.'/var/cache/prod');

        self::assertSame([$this->dir.'/var/cache/prod/'.PreloadWarmer::FILE], $files);
        self::assertStringContainsString("require_once __DIR__.'/../../phem/autoload.php';", (string) file_get_contents($files[0]));

        require $files[0];

        self::assertTrue(\function_exists($function));
    }

    public function testNoFileWithoutBuild(): void
    {
        self::assertSame([], $this->warmer()->warmUp($this->dir.'/var/cache/prod'));
        self::assertFileDoesNotExist($this->dir.'/var/cache/prod/'.PreloadWarmer::FILE);
    }

    public function testItIsNotOptional(): void
    {
        self::assertFalse($this->warmer()->isOptional(), 'the kernel adds the files of the required warmers to the preload when it builds the container');
    }

    private function warmer(): PreloadWarmer
    {
        return new PreloadWarmer(new Manifest($this->dir.'/var/phem'));
    }
}
