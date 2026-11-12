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

use Phem\Binding\PropError;
use Phem\Http\RouteValues;
use Phem\Manifest\Manifest;
use Phem\Metadata\ComponentMetadata;
use Phem\Path\EntityFinder;
use Phem\Path\EntityPathConverter;
use Phem\Path\PathProp;
use Phem\Path\PathResolver;
use Phem\Path\PathValueConverter;
use Phem\Path\ScalarPathConverter;
use Phem\Routing\PageLoader;
use Phem\Tests\Fixtures\PathPages\EntityPath;
use Phem\Tests\Fixtures\PathPages\ObjectPath;
use Phem\Tests\Fixtures\PathPages\Typed;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\ScientistFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PathTest extends TestCase
{
    private const NS = 'Phem\\Tests\\Fixtures\\PathPages\\';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phem-path-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testRouteWithPathValuesLoads(): void
    {
        $routes = $this->load('Known');

        self::assertSame('/known/{id}', $routes->get('phem_tests_fixtures_pathpages_known')?->getPath());
    }

    public function testPathVariableWithoutPathPropertyIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route "phem_tests_fixtures_pathpages_unknownvalue" has the value "slug", but the page "Phem\\Tests\\Fixtures\\PathPages\\UnknownValue" has no #[Path] property "slug".');

        $this->load('UnknownValue');
    }

    public function testDefaultWithoutPathPropertyIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has the value "mode"');

        $this->load('UnknownDefault');
    }

    public function testPropertyWithoutPathAttributeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no #[Path] property "id"');

        $this->load('WithoutPath');
    }

    public function testPathOnStatePropertyIsAnError(): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage('The #[Path] property "id" of <StatePath> must be a prop');

        $this->load('StatePath');
    }

    public function testAFunctionIsAPage(): void
    {
        $routes = $this->load('FunctionPage');

        self::assertSame('/function', $routes->get('phem_tests_fixtures_pathpages_functionpage')?->getPath(), 'the default name comes from the function');
        self::assertSame(self::NS.'FunctionPage', $routes->get('phem_tests_fixtures_pathpages_functionpage')->getDefault('_phem_page'));
        self::assertSame(['POST'], $routes->get('phem_event.phem_tests_fixtures_pathpages_functionpage')?->getMethods(), 'its components can have handlers');
        self::assertSame('/function-too', $routes->get('function_too')?->getPath(), 'a repeated #[Page]');
    }

    public function testRouteValueWithoutAParameterOfAFunctionPageIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route "phem_tests_fixtures_pathpages_functionvalue" has the value "slug", but the page "Phem\\Tests\\Fixtures\\PathPages\\FunctionValue" has no parameter "slug". Add one, or remove the value from the route.');

        $this->load('FunctionValue');
    }

    public function testDefaultWithoutAParameterOfAFunctionPageIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has the value "mode", but the page "Phem\\Tests\\Fixtures\\PathPages\\FunctionDefault" has no parameter "mode"');

        $this->load('FunctionDefault');
    }

    public function testRouteValuesOfAFunctionPageGoToItsParameters(): void
    {
        self::assertSame('/function-items/{id}', $this->load('FunctionItem')->get('phem_tests_fixtures_pathpages_functionitem')?->getPath());
        self::assertSame(['name' => 'scientist'], $this->load('FunctionMapped')->get('phem_tests_fixtures_pathpages_functionmapped')?->getDefault('_route_mapping'));
        self::assertSame(['id', 'request'], array_keys((new ComponentMetadata())->pathProps(self::NS.'FunctionItem')), 'the parameters with no route value get no value');
        self::assertSame(['id' => 7], self::resolver()->resolve(self::NS.'FunctionItem', ['id' => '7']), 'converted to the type of the parameter');
    }

    public function testRouteValueOfAFunctionPageMappedToABuiltinTypeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "name" to the parameter "id" of the page "Phem\\Tests\\Fixtures\\PathPages\\FunctionMappedToScalar", but its type is not an entity class.');

        $this->load('FunctionMappedToScalar');
    }

    public function testAPageThatIsNotLoadedIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The page "Phem\\Tests\\Fixtures\\PathPages\\Missing" is not loaded. Run `phemc build` again.');

        $this->load('Missing');
    }

    public function testPageAttributeIsARoute(): void
    {
        $routes = $this->load('Paged');

        self::assertSame('/paged', $routes->get('paged')?->getPath());
        self::assertSame(self::NS.'Paged', $routes->get('paged')->getDefault('_phem_page'));
        self::assertSame(['POST'], $routes->get('phem_event.paged')?->getMethods(), 'the event route of the page');
        self::assertSame('paged', $routes->get('phem_event.paged')->getDefault('_canonical_route'), 'an event has the name of the page route');
        self::assertSame('paged_i18n', $routes->get('phem_event.paged_i18n.en')?->getDefault('_canonical_route'));
        self::assertSame('/es/paged', $routes->get('paged_i18n.es')?->getPath(), 'a localized path');
        self::assertSame('es', $routes->get('paged_i18n.es')->getDefault('_locale'));
        self::assertSame('/en/paged', $routes->get('phem_event.paged_i18n.en')->getPath());
    }

    public function testDefaultValueOfAPathPropMakesTheRouteValueOptional(): void
    {
        $routes = $this->load('OptionalPage');
        $matcher = new UrlMatcher($routes, new RequestContext());

        self::assertSame(1, $routes->get('blog')?->getDefault('page'));
        self::assertSame(1, $routes->get('phem_event.blog')?->getDefault('page'), 'the event route too');
        self::assertSame(5, $routes->get('archive')?->getDefault('page'), 'a default of the route comes first');
        self::assertSame(['page' => 1, '_route' => 'blog'], array_intersect_key($matcher->match('/blog'), ['page' => 0, '_route' => 0]));
        self::assertSame('/blog', (new UrlGenerator($routes, new RequestContext()))->generate('blog'));
    }

    public function testDefaultValueOfAFunctionPageParameterMakesTheRouteValueOptional(): void
    {
        $route = $this->load('FunctionOptional')->get('phem_tests_fixtures_pathpages_functionoptional');

        self::assertSame('name', $route?->getDefault('sort'), 'a backed enum gives its value');
        self::assertSame(10, $route->getDefault('limit'), 'with an inline requirement');
        self::assertFalse($route->hasDefault('at'), 'a parameter that is not a route value');
    }

    public function testDefaultValueOfAMappedPropGoesToItsRouteValue(): void
    {
        $route = $this->load('OptionalEntity')->get('phem_tests_fixtures_pathpages_optionalentity');

        self::assertTrue($route?->hasDefault('id'));
        self::assertNull($route->getDefault('id'));
        self::assertFalse($route->hasDefault('scientist'));
    }

    public function testDefaultValueOfARouteValueThatIsNotLastIsNotARouteDefault(): void
    {
        $routes = $this->load('NotTrailing');
        $generator = new UrlGenerator($routes, new RequestContext());

        self::assertFalse($routes->get('note_edit')?->hasDefault('id'), '{id} is before "/edit"');
        self::assertSame(['id' => 0, 'tab' => 'main'], array_intersect_key($routes->get('note_tab')?->getDefaults() ?? [], ['id' => 0, 'tab' => 0]), 'the last two route values');
        self::assertSame('/notes', $generator->generate('note_tab'));

        $this->expectException(MissingMandatoryParametersException::class);
        $generator->generate('note_edit');
    }

    public function testDefaultValueOfARouteValueThatIsNotLastInAllPathsIsNotARouteDefault(): void
    {
        $routes = $this->load('NotTrailingInAllPaths');

        self::assertFalse($routes->get('book.es')?->hasDefault('section'), '{section} is before "/todo"');
        self::assertFalse($routes->get('book.en')?->hasDefault('section'), 'the routes of the paths have the same defaults');
        self::assertFalse($routes->get('book.en')->hasDefault('book'), '{section} after it is required now');
    }

    public function testUntypedPathPropHasTheDefaultNull(): void
    {
        $route = $this->load('UntypedPath')->get('phem_tests_fixtures_pathpages_untypedpath');

        self::assertTrue($route?->hasDefault('page'));
        self::assertNull($route->getDefault('page'));
    }

    public function testNullMappedRouteValueGivesTheEntityPropNull(): void
    {
        $request = new Request(attributes: ['_route_params' => ['id' => null, 'name' => 'Ada'], '_route_mapping' => ['id' => 'scientist', 'name' => ['author', 'name']]]);

        self::assertSame(['scientist' => null, 'author' => ['name' => 'Ada']], RouteValues::fromRequest($request));
    }

    public function testRequirementWithoutAPlaceholderNameIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A placeholder name must be a string (0 given). Did you forget to specify the placeholder key for the requirement "\d+" of the route "phem_tests_fixtures_pathpages_requirementwithoutkey" of the page "Phem\\Tests\\Fixtures\\PathPages\\RequirementWithoutKey"?');

        $this->load('RequirementWithoutKey');
    }

    public function testNameOfAPageIsAnAliasOfItsFirstRoute(): void
    {
        self::assertSame('paged', $this->load('Paged')->getAlias(self::NS.'Paged')?->getId());
        self::assertNull($this->load('Paged')->getAlias(self::NS.'Paged.es'), 'only the first route');
        self::assertSame('phem_tests_fixtures_pathpages_functionpage', $this->load('FunctionPage')->getAlias(self::NS.'FunctionPage')?->getId());
    }

    public function testAFunctionPageWithNoParametersHasNoPathProps(): void
    {
        self::assertSame([], (new ComponentMetadata())->pathProps(self::NS.'FunctionPage'));
    }

    public function testPathValuesHaveTheTypesOfTheProps(): void
    {
        $values = self::resolver()->resolve(Typed::class, ['id' => '42', 'price' => '9.5', 'draft' => 'true', 'slug' => '007', 'label' => 'not a path value']);

        self::assertSame(['id' => 42, 'price' => 9.5, 'draft' => true, 'slug' => '007'], $values);
    }

    public function testPathValuesKeepTheOtherPropsOut(): void
    {
        $binder = self::resolver();

        self::assertSame(['id' => 7], $binder->resolve(self::NS.'Known', ['id' => '7', 'label' => 'y']));
        self::assertSame(['id' => 1], $binder->resolve(self::NS.'Known', ['id' => 1]), 'a default keeps its type');
        self::assertSame([], $binder->resolve(self::NS.'Known', []));
    }

    public function testPathValueThatDoesNotMatchItsType(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The route value "id" of <Typed> is not a valid int: "abc".');

        self::resolver()->resolve(Typed::class, ['id' => 'abc']);
    }

    public function testRouteValueMappedToAnEntityLoads(): void
    {
        $route = $this->load('EntityPath')->get('phem_tests_fixtures_pathpages_entitypath');

        self::assertSame('/scientists/{id}', $route?->getPath());
        self::assertSame(['id' => 'scientist'], $route->getDefault('_route_mapping'));
    }

    public function testRouteValueMappedToNoPathPropIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "id" to "scientist", but the page "Phem\\Tests\\Fixtures\\PathPages\\MappedToNothing" has no #[Path] property "scientist".');

        $this->load('MappedToNothing');
    }

    public function testRouteValueMappedToABuiltinTypeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "slug" to the #[Path] property "id" of the page "Phem\\Tests\\Fixtures\\PathPages\\MappedToBuiltin", but its type is not an entity class.');

        $this->load('MappedToBuiltin');
    }

    public function testEntityPropGetsTheEntityOfItsIdentifierOrCriteria(): void
    {
        $binder = self::resolver(new ScientistFinder());

        self::assertEquals(['scientist' => new Scientist(1, 'Ada')], $binder->resolve(EntityPath::class, ['scientist' => '1']));
        self::assertEquals(['scientist' => new Scientist(2, 'Marie')], $binder->resolve(EntityPath::class, ['scientist' => ['name' => 'Marie']]));
    }

    public function testNullableEntityPropGetsNullWhenNoEntityMatches(): void
    {
        self::assertSame(['scientist' => null], self::resolver(new ScientistFinder())->resolve(self::NS.'NullableEntity', ['scientist' => '9']));
    }

    public function testEntityThatDoesNotExist(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No Scientist matches the route value "scientist" of <EntityPath>.');

        self::resolver(new ScientistFinder())->resolve(EntityPath::class, ['scientist' => ['id' => '9']]);
    }

    public function testClassTypeThatIsNotAnEntityIsAnError(): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage('The #[Path] prop "at" of <ObjectPath> has the type DateTimeImmutable, which is not a Doctrine entity.');

        self::resolver(new ScientistFinder())->resolve(ObjectPath::class, ['at' => '2026-10-16']);
    }

    public function testEntityPropNeedsAFinder(): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage('install doctrine/orm and doctrine/doctrine-bundle');

        self::resolver()->resolve(EntityPath::class, ['scientist' => '1']);
    }

    public function testConverterOfTheApplicationConvertsOtherTypes(): void
    {
        $dates = new class implements PathValueConverter {
            public function supports(PathProp $prop): bool
            {
                return \DateTimeImmutable::class === $prop->type->getName();
            }

            public function convert(PathProp $prop, mixed $value): \DateTimeImmutable
            {
                return new \DateTimeImmutable((string) $value);
            }
        };
        $resolver = new PathResolver(new ComponentMetadata(), [$dates, new ScalarPathConverter(), new EntityPathConverter()]);

        self::assertEquals(['at' => new \DateTimeImmutable('2026-10-16')], $resolver->resolve(ObjectPath::class, ['at' => '2026-10-16']));
    }

    private static function resolver(?EntityFinder $finder = null): PathResolver
    {
        return new PathResolver(new ComponentMetadata(), [new ScalarPathConverter(), new EntityPathConverter($finder)]);
    }

    private function load(string $page): RouteCollection
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [self::NS.$page => '']], true).';');

        return (new PageLoader(new Manifest($this->dir)))->load('.', 'phem');
    }
}
