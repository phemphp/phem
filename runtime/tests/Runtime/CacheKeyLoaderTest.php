<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Manifest\Manifest;
use Rephp\Twig\CacheKeyLoader;
use Twig\Loader\ArrayLoader;

final class CacheKeyLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/rephp-cache-key-'.bin2hex(random_bytes(4));
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

    public function testCacheKeyWithoutBuild(): void
    {
        $inner = new ArrayLoader(['page.twig' => '']);
        $loader = new CacheKeyLoader($inner, new Manifest($this->dir.'/missing', $this->dir));

        self::assertSame($inner->getCacheKey('page.twig').':', $loader->getCacheKey('page.twig'));
    }

    /** @param array<string, array<string, mixed>> $components */
    private function cacheKey(array $components): string
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['components' => $components], true).';');
        $loader = new CacheKeyLoader(new ArrayLoader(['page.twig' => '']), new Manifest($this->dir, $this->dir));

        return $loader->getCacheKey('page.twig');
    }
}
