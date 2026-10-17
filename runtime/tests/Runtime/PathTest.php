<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu\Tests\Runtime;

use Mamu\Binding\PropError;
use Mamu\Manifest\Manifest;
use Mamu\Metadata\ComponentMetadata;
use Mamu\Path\EntityFinder;
use Mamu\Path\EntityPathConverter;
use Mamu\Path\PathProp;
use Mamu\Path\PathResolver;
use Mamu\Path\PathValueConverter;
use Mamu\Path\ScalarPathConverter;
use Mamu\Routing\PageLoader;
use Mamu\Tests\Fixtures\PathPages\EntityPath;
use Mamu\Tests\Fixtures\PathPages\ObjectPath;
use Mamu\Tests\Fixtures\PathPages\Typed;
use Mamu\Tests\Fixtures\Scientist;
use Mamu\Tests\Fixtures\ScientistFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PathTest extends TestCase
{
    private const NS = 'Mamu\\Tests\\Fixtures\\PathPages\\';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mamu-path-'.bin2hex(random_bytes(4));
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

        self::assertSame('/known/{id}', $routes->get('mamu_tests_fixtures_pathpages_known')?->getPath());
    }

    public function testPathVariableWithoutPathParameterIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route "mamu_tests_fixtures_pathpages_unknownvalue" has the value "slug", but the page "Mamu\\Tests\\Fixtures\\PathPages\\UnknownValue" has no #[Path] parameter "slug".');

        $this->load('UnknownValue');
    }

    public function testDefaultWithoutPathParameterIsAnError(): void
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

    public function testPathOnVariadicParameterIsAnError(): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage('The #[Path] parameter "tag" of <VariadicPath> cannot be variadic.');

        $this->load('VariadicPath');
    }

    public function testPathValuesHaveTheTypesOfTheProps(): void
    {
        $values = self::resolver()->resolve(Typed::class, ['id' => '42', 'price' => '9.5', 'draft' => 'true', 'slug' => '007', 'label' => 'not a path value']);

        self::assertSame(['id' => 42, 'price' => 9.5, 'draft' => true, 'slug' => '007'], $values);
    }

    public function testPathValuesOfAFunctionPage(): void
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
        $route = $this->load('EntityPath')->get('mamu_tests_fixtures_pathpages_entitypath');

        self::assertSame('/scientists/{id}', $route?->getPath());
        self::assertSame(['id' => 'scientist'], $route->getDefault('_route_mapping'));
    }

    public function testRouteValueMappedToNoPathPropIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "id" to "scientist", but the page "Mamu\\Tests\\Fixtures\\PathPages\\MappedToNothing" has no #[Path] property "scientist".');

        $this->load('MappedToNothing');
    }

    public function testRouteValueMappedToABuiltinTypeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "slug" to the #[Path] property "id" of the page "Mamu\\Tests\\Fixtures\\PathPages\\MappedToBuiltin", but its type is not an entity class.');

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
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [self::NS.$page]], true).';');

        return (new PageLoader(new Manifest($this->dir, $this->dir)))->load('.', 'mamu');
    }
}
