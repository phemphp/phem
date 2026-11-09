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

use Phem\Handler\ObjectIdentifier;
use Phem\Manifest\Manifest;
use Phem\RedirectTo;
use Phem\Routing\PageLoader;
use Phem\Routing\PageUrlGenerator;
use Phem\Routing\PageUrlKeys;
use Phem\Routing\PageUrlValues;
use Phem\Tests\Fixtures\PathPages\EntityPath;
use Phem\Tests\Fixtures\PathPages\Known;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\Size;
use Phem\Twig\ConstantFunction;
use Phem\Twig\PhemExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

/**
 * The Twig functions of the class names in markup: `OrderStatus.Cancelled` compiles to
 * `phem_constant()`, and `path(ProductPage)` to `phem_path()`.
 */
final class ClassNamesInMarkupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-classes-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [Known::class => '', EntityPath::class => '']], true).';');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testConstantCompilesToTheCaseOfTheEnum(): void
    {
        $twig = $this->twig(['t' => "{{ size == phem_constant('Phem\\\\Tests\\\\Fixtures\\\\Size::Large') ? 'large' : 'small' }}"]);

        self::assertSame('large', $twig->render('t', ['size' => Size::Large]));
        self::assertSame('small', $twig->render('t', ['size' => Size::Small]));
        self::assertStringContainsString('\\Phem\\Tests\\Fixtures\\Size::Large', $twig->compileSource($twig->getLoader()->getSourceContext('t')));
    }

    public function testDebugModeReadsTheConstantWhenTheTemplateRenders(): void
    {
        $twig = $this->twig(['t' => "{{ phem_constant('Phem\\\\Tests\\\\Fixtures\\\\Size::Large').value }}"], debug: true);

        self::assertSame('l', $twig->render('t'));
        self::assertStringContainsString('ConstantFunction::read("Phem\\\\Tests\\\\Fixtures\\\\Size::Large")', $twig->compileSource($twig->getLoader()->getSourceContext('t')));

        // A case that a change of the class removed.
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('The class "Phem\Tests\Fixtures\Size" has no case or constant "Huge".');

        ConstantFunction::read('Phem\Tests\Fixtures\Size::Huge');
    }

    public function testUnknownCaseIsAnErrorWhenTheTemplateCompiles(): void
    {
        $twig = $this->twig(['t' => "{{ phem_constant('Phem\\\\Tests\\\\Fixtures\\\\Size::Huge') }}"]);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('The class "Phem\Tests\Fixtures\Size" has no case or constant "Huge"');

        $twig->load('t');
    }

    public function testUnknownClassIsAnErrorWhenTheTemplateCompiles(): void
    {
        $twig = $this->twig(['t' => "{{ phem_constant('App\\\\Missing::A') }}"]);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('The markup reads "App\Missing.A", but the class "App\Missing" does not exist.');

        $twig->load('t');
    }

    public function testPathAndUrlOfAPage(): void
    {
        $twig = $this->twig([
            'path' => "{{ phem_path('Phem\\\\Tests\\\\Fixtures\\\\PathPages\\\\Known', {id: 5, tab: 'notes'}) }}",
            'url' => "{{ phem_url('Phem\\\\Tests\\\\Fixtures\\\\PathPages\\\\Known') }}",
        ]);

        self::assertSame('/known/5?tab=notes', $twig->render('path'));
        self::assertSame('http://localhost/known', $twig->render('url'));
    }

    public function testThirdArgumentOfPathAndUrlIsTheOneOfTwig(): void
    {
        $twig = $this->twig([
            'relative' => "{{ phem_path('Phem\\\\Tests\\\\Fixtures\\\\PathPages\\\\Known', {id: 5}, true) }}",
            'scheme' => "{{ phem_url('Phem\\\\Tests\\\\Fixtures\\\\PathPages\\\\Known', {id: 5}, true) }}",
        ]);

        self::assertSame('known/5', $twig->render('relative'), 'a relative path, as path(name, values, true)');
        self::assertSame('//localhost/known/5', $twig->render('scheme'), 'a URL with no scheme, as url(name, values, true)');
    }

    public function testValueOfAPropGivesItsRouteValue(): void
    {
        // `/scientists/{id:scientist}`: the prop `scientist` gives the route value `id`.
        $scientist = new Scientist(42, 'Curie');

        self::assertSame('/scientists/42', $this->urls()->generate(EntityPath::class, ['scientist' => $scientist]));
        self::assertSame('/scientists/42', $this->urls()->generate(EntityPath::class, ['id' => $scientist]), 'the route value name, with an object');
        self::assertSame('/scientists/7', $this->urls()->generate(EntityPath::class, ['id' => 7]), 'the route value name passes as it is');
        self::assertSame('/scientists/42', $this->urls()->redirect(new RedirectTo(EntityPath::class, ['scientist' => $scientist]))->getTargetUrl(), 'a redirect');
        self::assertSame('/known?size=l', $this->urls()->generate(Known::class, ['size' => Size::Large]), 'a backed enum in the query string');
    }

    public function testFieldOfTheIdentifierOrAGetterOrAnEnum(): void
    {
        $routes = new RouteCollection();
        $routes->add('slug', self::page('/posts/{slug:post}'));
        $routes->add('field', self::page('/posts/{name:post.title}'));
        $routes->add('unmapped', self::page('/items/{item}'));
        $routes->add('size', self::page('/sizes/{size}'));
        $identifier = new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                return $value instanceof \stdClass ? ['id' => 9] : null;
            }
        };
        $values = new PageUrlValues(new PageUrlKeys($this->router($routes)), $identifier);
        $post = new class {
            public function getSlug(): string
            {
                return 'hello';
            }

            public string $title = 'Hi';
        };

        self::assertSame(['slug' => 'hello'], $values->of('slug', ['post' => $post]), 'a getter');
        self::assertSame(['name' => 'Hi'], $values->of('field', ['post' => $post]), 'the field of `post.title`');
        self::assertSame(['item' => 9], $values->of('unmapped', ['item' => new \stdClass()]), 'the identifier');
        self::assertSame(['size' => 'l'], $values->of('size', ['size' => Size::Large]), 'a backed enum');
        self::assertSame(['tab' => 'a'], $values->of('missing', ['tab' => 'a']), 'a route that does not exist');
        self::assertSame([], $values->of('slug', []), 'no values');
    }

    public function testPropWithTwoRouteValuesGivesBoth(): void
    {
        $routes = new RouteCollection();
        $routes->add('post', self::page('/blog/{year:post.year}/{slug:post}'));
        $values = new PageUrlValues(new PageUrlKeys($this->router($routes)));
        $post = new class {
            public int $year = 2026;
            public string $slug = 'hello';
        };

        self::assertSame(['year' => 2026, 'slug' => 'hello'], $values->of('post', ['post' => $post]));
        self::assertSame(['year' => 2025, 'slug' => 'a'], $values->of('post', ['year' => 2025, 'slug' => 'a']), 'the route value names');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The value "post" of the route "post" gives the route values "year", "slug": give an object, or each route value.');

        $values->of('post', ['post' => 5]);
    }

    public function testLocalizedPageGivesTheRouteValues(): void
    {
        // The aliases of a localized page have the locale.
        $routes = new RouteCollection();
        $routes->add('product.en', self::page('/products/{id:product}', 'en'));
        $routes->add('product.es', self::page('/productos/{id:product}', 'es'));
        $routes->addAlias('App\\ProductPage.en', 'product.en');
        $routes->addAlias('App\\ProductPage.es', 'product.es');

        self::assertSame(['id' => 42], (new PageUrlValues(new PageUrlKeys($this->router($routes))))->of('App\\ProductPage', ['product' => new Scientist(42, 'Curie')]));
    }

    public function testCacheFileKeepsTheKeys(): void
    {
        $routes = new RouteCollection();
        $routes->add('slug', self::page('/posts/{slug:post}'));
        $file = $this->dir.'/phem/page_url_keys.php';

        // The warmup writes into the directory that it warms.
        $keys = new PageUrlKeys($this->router($routes), $this->dir.'/elsewhere');
        $keys->warmUp($this->dir);
        self::assertSame(['slug' => ['post' => [['slug', 'slug']], 'slug' => [['slug', 'slug']]]], require $file);

        // A new object reads the file, not the routes.
        $routes->add('other', self::page('/other/{id}'));
        self::assertSame([], (new PageUrlKeys($this->router($routes), $this->dir))->of('other'));
        self::assertSame(['post' => [['slug', 'slug']], 'slug' => [['slug', 'slug']]], (new PageUrlKeys($this->router($routes), $this->dir))->of('slug'));
    }

    public function testObjectWithoutTheFieldIsAnError(): void
    {
        $routes = new RouteCollection();
        $routes->add('slug', self::page('/posts/{slug:post}'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The value "post" of the route "slug" is a "stdClass" with no field "slug"');

        (new PageUrlValues(new PageUrlKeys($this->router($routes))))->of('slug', ['post' => new \stdClass()]);
    }

    /** @param array<string, string> $templates */
    private function twig(array $templates, bool $debug = false): Environment
    {
        $twig = new Environment(new ArrayLoader($templates), ['debug' => $debug]);
        $twig->addExtension(new PhemExtension(new Manifest($this->dir)));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([PageUrlGenerator::class => $this->urls(...)]));

        return $twig;
    }

    private function urls(): PageUrlGenerator
    {
        $routes = (new PageLoader(new Manifest($this->dir)))->load('.', 'phem');
        $router = $this->router($routes);

        return new PageUrlGenerator($router, new PageUrlValues(new PageUrlKeys($router)));
    }

    /** A page route, as PageLoader adds it. */
    private static function page(string $path, ?string $locale = null): Route
    {
        return new Route($path, ['_phem_page' => 'App\\Page'] + (null !== $locale ? ['_locale' => $locale] : []));
    }

    private function router(RouteCollection $routes): Router
    {
        return new Router(new ClosureLoader(), static fn (): RouteCollection => $routes, context: new RequestContext());
    }
}
