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
use Phem\Manifest\SourceMap;
use PHPUnit\Framework\TestCase;

final class SourceMapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-source-map-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['templates' => [
            'Ui/HomePage.0.twig' => ['source' => 'src/Ui/HomePage.phpm', 'line' => 10],
            'vendor/acme/ui/Badge.0.twig' => ['source' => '/opt/packages/ui/components/Badge.phpm', 'line' => 4],
        ]], true).';');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testASourceInTheProjectIsInTheProjectDir(): void
    {
        $location = (new SourceMap(new Manifest($this->dir), '/app'))->location('@phpm/Ui/HomePage.0.twig', 3);

        self::assertSame(['file' => 'src/Ui/HomePage.phpm', 'path' => '/app/src/Ui/HomePage.phpm', 'line' => 12], $location);
    }

    public function testASourceOutsideTheProjectKeepsItsAbsolutePath(): void
    {
        $location = (new SourceMap(new Manifest($this->dir), '/app'))->location('@phpm/vendor/acme/ui/Badge.0.twig', 1);

        self::assertSame('/opt/packages/ui/components/Badge.phpm', $location['path'] ?? null);
    }
}
