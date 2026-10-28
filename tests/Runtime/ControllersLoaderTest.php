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
use Phem\Routing\ControllersLoader;
use Phem\Routing\PageLoader;
use Phem\Tests\Fixtures\PathPages\Paged;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class ControllersLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-controllers-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheResourceHasTheRoutesOfTheControllersAndOfThePages(): void
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [Paged::class]], true).';');
        $loader = new ControllersLoader(new ClosureLoader(), new PageLoader(new Manifest($this->dir, $this->dir)));

        $routes = $loader->load(static function (): RouteCollection {
            $routes = new RouteCollection();
            $routes->add('app_home', new Route('/'));

            return $routes;
        }, 'closure');

        self::assertSame('/', $routes->get('app_home')?->getPath());
        self::assertSame('/paged', $routes->get('paged')?->getPath());
        self::assertSame('/paged', $routes->get('phem_event.paged')?->getPath());
    }

    public function testTheResourceWithoutBuild(): void
    {
        $loader = new ControllersLoader(new AttributeServicesLoader(), new PageLoader(new Manifest($this->dir.'/missing', $this->dir)));

        self::assertCount(0, $loader->load('routing.controllers'));
    }

    public function testItSupportsTheResourceOfTheDecoratedLoader(): void
    {
        $loader = new ControllersLoader(new AttributeServicesLoader(), new PageLoader(new Manifest($this->dir, $this->dir)));

        self::assertTrue($loader->supports('routing.controllers'));
        self::assertFalse($loader->supports('.', 'phem'));
    }

    public function testItGivesTheResolverToTheDecoratedLoader(): void
    {
        $controllers = new AttributeServicesLoader();
        $loader = new ControllersLoader($controllers, new PageLoader(new Manifest($this->dir, $this->dir)));
        $resolver = new LoaderResolver([$loader]);

        self::assertSame($resolver, $controllers->getResolver());
        self::assertSame($resolver, $loader->getResolver());
    }
}
