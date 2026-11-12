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
use Phem\Routing\PageRoutes;
use Phem\Routing\PageRoutesLoader;
use Phem\Tests\Fixtures\PathPages\EmptyPaths;
use Phem\Tests\Fixtures\PathPages\Paged;
use Phem\Tests\Fixtures\PathPages\RoutedInConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Loader\Configurator\Traits\HostTrait;
use Symfony\Component\Routing\Loader\Configurator\Traits\PrefixTrait;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PageRoutesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-page-routes-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
        // A package page: the application doesn't need to route it.
        $this->manifest([Paged::class => '', RoutedInConfig::class => 'acme/ui']);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testEachPageRouteHasAnEventRouteBeforeIt(): void
    {
        $routes = new RouteCollection();
        $routes->add('first', new Route('/first'));
        $routes->addCollection($this->pages());
        $routes->add('last', new Route('/last'), 5);

        $routes = $this->complete($routes);

        self::assertSame(['last', 'first', 'phem_event.paged', 'paged', 'phem_event.paged_i18n.en', 'paged_i18n.en', 'phem_event.paged_i18n.es', 'paged_i18n.es'], array_keys($routes->all()), 'the priorities and the order stay');
        self::assertSame(['POST'], $routes->get('phem_event.paged')?->getMethods());
        self::assertSame('phem.controller.event', $routes->get('phem_event.paged')->getDefault('_controller'));
        self::assertSame('paged_i18n', $routes->get('phem_event.paged_i18n.en')?->getDefault('_canonical_route'));
        self::assertSame('paged', $routes->getAlias(Paged::class)?->getId(), 'the aliases stay');
        self::assertSame(array_keys($routes->all()), array_keys($this->complete($routes)->all()), 'a second pass adds nothing');
    }

    public function testEventOfAPageUnderALocalizedPrefixHasTheNameOfThePageRoute(): void
    {
        $routes = $this->pages();
        (new class {
            use PrefixTrait;

            public function prefix(RouteCollection $routes): void
            {
                $this->addPrefix($routes, ['en' => '/en', 'es' => '/es'], true);
            }
        })->prefix($routes);

        $routes = $this->complete($routes);

        self::assertSame('paged', $this->match($routes, 'GET', '/en/paged'));
        self::assertSame('paged', $this->match($routes, 'POST', '/en/paged'), 'the access rules on the route name apply to the events');
        self::assertSame('phem.controller.event', $routes->get('phem_event.paged.en')?->getDefault('_controller'));
    }

    public function testEventOfAPageUnderALocalizedHostHasTheNameOfThePageRoute(): void
    {
        $routes = $this->pages();
        (new class {
            use HostTrait;

            public function host(RouteCollection $routes): void
            {
                $this->addHost($routes, ['en' => 'en.example.com', 'es' => 'es.example.com']);
            }
        })->host($routes);

        $routes = $this->complete($routes);

        self::assertSame('paged', $this->match($routes, 'POST', '/paged', 'es.example.com'));
    }

    public function testEventOfAPageUnderANamePrefixHasTheNameOfThePageRoute(): void
    {
        $routes = $this->pages();
        $routes->addNamePrefix('acme_');

        $routes = $this->complete($routes);

        self::assertSame('acme_paged', $this->match($routes, 'POST', '/paged'));
        self::assertNotNull($routes->get('phem_event.acme_paged'));
    }

    public function testRouteOfARouteFileWhoseControllerIsAPageIsAPageRoute(): void
    {
        $routes = new RouteCollection();
        $routes->add('item', new Route('/items/{id}', ['_controller' => '\\'.RoutedInConfig::class], methods: ['GET']));

        $routes = $this->complete($routes);

        self::assertSame('phem.controller.page', $routes->get('item')?->getDefault('_controller'));
        self::assertSame(RoutedInConfig::class, $routes->get('item')->getDefault('_phem_page'));
        self::assertSame(['GET'], $routes->get('phem_event.item')?->getDefault('_phem_methods'));
        self::assertSame('item', $routes->getAlias(RoutedInConfig::class)?->getId(), 'the name of the page is an alias of its first route');
        self::assertSame('/items/3', (new UrlGenerator($routes, new RequestContext()))->generate(RoutedInConfig::class, ['id' => 3]));
    }

    public function testLocalizedRouteOfARouteFileGetsAnAliasForEachLocale(): void
    {
        $routes = new RouteCollection();
        foreach (['en' => '/items/{id}', 'es' => '/articulos/{id}'] as $locale => $path) {
            $routes->add('item.'.$locale, new Route($path, ['_controller' => RoutedInConfig::class, '_locale' => $locale, '_canonical_route' => 'item']));
        }

        $routes->add('item_too', new Route('/more/{id}', ['_controller' => RoutedInConfig::class]));

        $routes = $this->complete($routes);

        self::assertSame('item.en', $routes->getAlias(RoutedInConfig::class.'.en')?->getId());
        self::assertSame('item.es', $routes->getAlias(RoutedInConfig::class.'.es')?->getId());
        self::assertNull($routes->getAlias(RoutedInConfig::class), 'only the first route');
        self::assertSame('item', $routes->get('phem_event.item.es')?->getDefault('_canonical_route'));
    }

    public function testRouteValueOfARouteFileNeedsAPathProperty(): void
    {
        $routes = new RouteCollection();
        $routes->add('item', new Route('/items/{slug}', ['_controller' => RoutedInConfig::class]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route "item" has the value "slug", but the page "Phem\Tests\Fixtures\PathPages\RoutedInConfig" has no #[Path] property "slug".');

        $this->complete($routes);
    }

    public function testDeprecatedAliasStaysDeprecated(): void
    {
        $routes = $this->pages();
        $routes->addAlias('old_paged', 'paged')->setDeprecated('acme/ui', '1.2', 'The "%alias_id%" route is deprecated.');

        $alias = $this->complete($routes)->getAlias('old_paged');

        self::assertTrue($alias?->isDeprecated());
        self::assertSame(['package' => 'acme/ui', 'version' => '1.2', 'message' => 'The "old_paged" route is deprecated.'], $alias->getDeprecation('old_paged'));
    }

    public function testAPageWithNoPathHasNoRouteOfItsOwn(): void
    {
        $this->manifest([RoutedInConfig::class => '']);

        self::assertCount(0, (new PageLoader(new Manifest($this->dir)))->load('.', 'phem'));
    }

    public function testAPageOfTheApplicationWithNoPathNeedsARoute(): void
    {
        $this->manifest([RoutedInConfig::class => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The page "Phem\Tests\Fixtures\PathPages\RoutedInConfig" has a #[Page] with no path, and no route of the application routes it. Add a route with the controller "Phem\Tests\Fixtures\PathPages\RoutedInConfig" to a route file, or give the #[Page] a path.');

        $this->complete(new RouteCollection());
    }

    public function testAPageOfTheApplicationWithNoPathAndARouteLoads(): void
    {
        $this->manifest([RoutedInConfig::class => '']);
        $routes = new RouteCollection();
        $routes->add('item', new Route('/items/{id}', ['_controller' => RoutedInConfig::class]));

        self::assertNotNull($this->complete($routes)->get('phem_event.item'));
    }

    public function testTheControllerOfARouteFileCanBeAParameter(): void
    {
        $routes = new RouteCollection();
        $routes->add('item', new Route('/items/{id}', ['_controller' => '%app.item_page%']));

        $routes = (new PageRoutes(new Manifest($this->dir), parameters: new ParameterBag(['app.item_page' => RoutedInConfig::class])))->complete($routes);

        self::assertSame(RoutedInConfig::class, $routes->get('item')?->getDefault('_phem_page'));
        self::assertNotNull($routes->get('phem_event.item'));
    }

    public function testAnImportCanAddRouteValuesThatThePageDoesNotHave(): void
    {
        // PageLoader checks the values of a #[Page] route before the import adds its own.
        $routes = $this->pages();
        $routes->addPrefix('/{tenant}');
        $routes->addDefaults(['theme' => 'dark']);
        $routes->setHost('{region}.example.com');

        $routes = $this->complete($routes);

        self::assertSame('/{tenant}/paged', $routes->get('phem_event.paged')?->getPath());
        self::assertSame('paged', $this->match($routes, 'POST', '/acme/paged', 'eu.example.com'));
    }

    public function testAnEmptyListOfPathsHasNoRouteAndKeepsTheAliasForTheNextRoute(): void
    {
        $this->manifest([EmptyPaths::class => '']);

        $routes = (new PageLoader(new Manifest($this->dir)))->load('.', 'phem');

        self::assertSame(['empty_paths'], array_keys($routes->all()));
        self::assertSame('empty_paths', $routes->getAlias(EmptyPaths::class)?->getId());
    }

    public function testTheLoaderOfTheRouterCompletesTheRoutes(): void
    {
        $loader = new PageRoutesLoader(new ClosureLoader(), new PageRoutes(new Manifest($this->dir)));
        $resolver = new LoaderResolver([$loader]);

        $routes = $loader->load(fn (): RouteCollection => $this->pages(), 'closure');

        self::assertInstanceOf(RouteCollection::class, $routes);
        self::assertNotNull($routes->get('phem_event.paged'));
        self::assertTrue($loader->supports(static fn () => null, 'closure'));
        self::assertSame($resolver, $loader->getResolver());
    }

    /** @param array<string, string> $pages page => package */
    private function manifest(array $pages): void
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => $pages], true).';');
    }

    private function pages(): RouteCollection
    {
        return (new PageLoader(new Manifest($this->dir)))->load('.', 'phem');
    }

    private function complete(RouteCollection $routes): RouteCollection
    {
        return (new PageRoutes(new Manifest($this->dir)))->complete($routes);
    }

    private function match(RouteCollection $routes, string $method, string $path, string $host = 'localhost'): string
    {
        $match = (new UrlMatcher($routes, new RequestContext(method: $method, host: $host)))->match($path);

        return (string) $match['_route'];
    }
}
