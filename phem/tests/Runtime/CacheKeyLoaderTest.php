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
use Phem\Twig\CacheKeyLoader;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

final class CacheKeyLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-cache-key-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testCacheKeyChangesWithTheComponentKind(): void
    {
        $template = $this->cacheKey(['App\\MyButton' => ['kind' => 'template', 'template' => 'a.0.twig', 'props' => []]]);
        $function = $this->cacheKey(['App\\MyButton' => ['kind' => 'function', 'props' => []]]);
        $otherTemplate = $this->cacheKey(['App\\MyButton' => ['kind' => 'template', 'template' => 'a.1.twig', 'props' => []]]);
        $otherProps = $this->cacheKey(['App\\MyButton' => ['kind' => 'template', 'template' => 'a.0.twig', 'props' => ['label']]]);

        self::assertNotSame($template, $function);
        self::assertNotSame($template, $otherTemplate);
        self::assertSame($template, $otherProps, 'props are checked at runtime, not compiled into the template');
    }

    public function testCacheKeyChangesWithTheRuntime(): void
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['components' => []], true).';');
        $inner = new ArrayLoader(['page.twig' => '']);
        $manifest = new Manifest($this->dir, $this->dir);

        // The key of the runtime before it removed @Phem/slot_host.twig. Its compiled templates
        // extend that template, so the new runtime must not use them.
        $old = $inner->getCacheKey('page.twig').':'.$manifest->twigSignature();

        self::assertNotSame($old, (new CacheKeyLoader($inner, $manifest))->getCacheKey('page.twig'));
    }

    public function testCacheKeyWithoutBuild(): void
    {
        $inner = new ArrayLoader(['page.twig' => '']);
        $loader = new CacheKeyLoader($inner, new Manifest($this->dir.'/missing', $this->dir));

        self::assertSame($inner->getCacheKey('page.twig').'::'.CacheKeyLoader::FORMAT, $loader->getCacheKey('page.twig'));
    }

    /** @param array<string, array<string, mixed>> $components */
    private function cacheKey(array $components): string
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['components' => $components], true).';');
        $loader = new CacheKeyLoader(new ArrayLoader(['page.twig' => '']), new Manifest($this->dir, $this->dir));

        return $loader->getCacheKey('page.twig');
    }
}
