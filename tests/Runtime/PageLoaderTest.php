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
use Phem\Routing\PageLoader;
use Phem\Tests\Fixtures\PathPages\Known;
use Phem\Tests\Fixtures\PathPages\Paged;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PageLoaderTest extends TestCase
{
    private const FUNCTION_PAGE = 'Phem\Tests\Fixtures\PathPages\FunctionPage';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-pages-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheDotResourceHasOnlyThePagesOfTheApplication(): void
    {
        $this->manifest([Paged::class => '', Known::class => 'acme/ui', self::FUNCTION_PAGE => 'acme/ui']);

        $routes = $this->loader()->load('.', 'phem');

        self::assertNotNull($routes->get('paged'));
        self::assertNull($routes->get(PageLoader::routeName(Known::class)));
        self::assertNull($routes->get(PageLoader::routeName(self::FUNCTION_PAGE)));
    }

    public function testAPackageResourceHasOnlyThePagesOfThePackage(): void
    {
        $this->manifest([Paged::class => '', Known::class => 'acme/ui', self::FUNCTION_PAGE => 'acme/ui']);

        $routes = $this->loader()->load('acme/ui', 'phem');

        self::assertNull($routes->get('paged'));
        self::assertSame('/known/{id}', $routes->get(PageLoader::routeName(Known::class))?->getPath());
        self::assertSame('/function', $routes->get(PageLoader::routeName(self::FUNCTION_PAGE))?->getPath());
    }

    public function testAPackageThatIsNotInstalledIsAnError(): void
    {
        $this->manifest([Paged::class => '', Known::class => 'acme/ui', self::FUNCTION_PAGE => 'acme/ui']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The package "acme/missing" is not installed, and it has no pages in the build. The packages with pages: "acme/ui".');

        $this->loader()->load('acme/missing', 'phem');
    }

    public function testAPackageThatIsNotInstalledIsAnErrorWhenNoPackageHasPages(): void
    {
        $this->manifest([Paged::class => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The packages with pages: none.');

        $this->loader()->load('acme/ui', 'phem');
    }

    public function testAnInstalledPackageWithNoPagesInTheBuildHasNoRoutesYet(): void
    {
        // The build is older than `composer require`: the routes come with the next build.
        $this->manifest([Paged::class => '']);

        $routes = $this->loader()->load('symfony/routing', 'phem');

        self::assertCount(0, $routes);
        self::assertContains($this->dir.'/manifest.php', array_map(static fn ($r): string => (string) $r, $routes->getResources()));
    }

    public function testAManifestOfAnOlderPhemcIsAnError(): void
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [Paged::class]], true).';');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('comes from another version of phemc. Run `phemc build` again.');

        $this->loader()->load('.', 'phem');
    }

    public function testNoManifestHasNoRoutes(): void
    {
        $routes = (new PageLoader(new Manifest($this->dir.'/missing')))->load('acme/ui', 'phem');

        self::assertCount(0, $routes);
    }

    /** @param array<string, string> $pages */
    private function manifest(array $pages): void
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => $pages], true).';');
    }

    private function loader(): PageLoader
    {
        return new PageLoader(new Manifest($this->dir));
    }
}
