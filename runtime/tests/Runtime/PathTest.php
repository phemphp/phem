<?php

declare(strict_types=1);

namespace Rephp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Rephp\Manifest;
use Rephp\Rendering\PropBinder;
use Rephp\Rendering\PropError;
use Rephp\Routing\PageLoader;
use Rephp\Tests\Fixtures\PathPages\EntityPath;
use Rephp\Tests\Fixtures\PathPages\ObjectPath;
use Rephp\Tests\Fixtures\PathPages\Typed;
use Rephp\Tests\Fixtures\Scientist;
use Rephp\Tests\Fixtures\ScientistFinder;
use Symfony\Component\Routing\RouteCollection;

require_once \dirname(__DIR__).'/Fixtures/PathPages.php';

final class PathTest extends TestCase
{
    private const NS = 'Rephp\\Tests\\Fixtures\\PathPages\\';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/rephp-path-'.bin2hex(random_bytes(4));
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

        self::assertSame('/known/{id}', $routes->get('rephp_tests_fixtures_pathpages_known')?->getPath());
    }

    public function testPathVariableWithoutPathParameterIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route "rephp_tests_fixtures_pathpages_unknownvalue" has the value "slug", but the page "Rephp\\Tests\\Fixtures\\PathPages\\UnknownValue" has no #[Path] parameter "slug".');

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
        $values = (new PropBinder())->pathValues(Typed::class, ['id' => '42', 'price' => '9.5', 'draft' => 'true', 'slug' => '007', 'label' => 'not a path value']);

        self::assertSame(['id' => 42, 'price' => 9.5, 'draft' => true, 'slug' => '007'], $values);
    }

    public function testPathValuesOfAFunctionPage(): void
    {
        $binder = new PropBinder();

        self::assertSame(['id' => 7], $binder->pathValues(self::NS.'Known', ['id' => '7', 'label' => 'y']));
        self::assertSame(['id' => 1], $binder->pathValues(self::NS.'Known', ['id' => 1]), 'a default keeps its type');
        self::assertSame([], $binder->pathValues(self::NS.'Known', []));
    }

    public function testPathValueThatDoesNotMatchItsType(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The route value "id" of <Typed> is not a valid int: "abc".');

        (new PropBinder())->pathValues(Typed::class, ['id' => 'abc']);
    }

    public function testRouteValueMappedToAnEntityLoads(): void
    {
        $route = $this->load('EntityPath')->get('rephp_tests_fixtures_pathpages_entitypath');

        self::assertSame('/scientists/{id}', $route?->getPath());
        self::assertSame(['id' => 'scientist'], $route->getDefault('_route_mapping'));
    }

    public function testRouteValueMappedToNoPathPropIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "id" to "scientist", but the page "Rephp\\Tests\\Fixtures\\PathPages\\MappedToNothing" has no #[Path] property "scientist".');

        $this->load('MappedToNothing');
    }

    public function testRouteValueMappedToABuiltinTypeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maps the value "slug" to the #[Path] property "id" of the page "Rephp\\Tests\\Fixtures\\PathPages\\MappedToBuiltin", but its type is not an entity class.');

        $this->load('MappedToBuiltin');
    }

    public function testEntityPropGetsTheEntityOfItsIdentifierOrCriteria(): void
    {
        $binder = new PropBinder(new ScientistFinder());

        self::assertEquals(['scientist' => new Scientist(1, 'Ada')], $binder->pathValues(EntityPath::class, ['scientist' => '1']));
        self::assertEquals(['scientist' => new Scientist(2, 'Marie')], $binder->pathValues(EntityPath::class, ['scientist' => ['name' => 'Marie']]));
    }

    public function testNullableEntityPropGetsNullWhenNoEntityMatches(): void
    {
        self::assertSame(['scientist' => null], (new PropBinder(new ScientistFinder()))->pathValues(self::NS.'NullableEntity', ['scientist' => '9']));
    }

    public function testEntityThatDoesNotExist(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No Scientist matches the route value "scientist" of <EntityPath>.');

        (new PropBinder(new ScientistFinder()))->pathValues(EntityPath::class, ['scientist' => ['id' => '9']]);
    }

    public function testClassTypeThatIsNotAnEntityIsAnError(): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage('The #[Path] prop "at" of <ObjectPath> has the type DateTimeImmutable, which is not a Doctrine entity.');

        (new PropBinder(new ScientistFinder()))->pathValues(ObjectPath::class, ['at' => '2026-10-16']);
    }

    public function testEntityPropNeedsAFinder(): void
    {
        $this->expectException(PropError::class);
        $this->expectExceptionMessage('install doctrine/orm and doctrine/doctrine-bundle');

        (new PropBinder())->pathValues(EntityPath::class, ['scientist' => '1']);
    }

    private function load(string $page): RouteCollection
    {
        file_put_contents($this->dir.'/manifest.php', '<?php return '.var_export(['pages' => [self::NS.$page]], true).';');

        return (new PageLoader(new Manifest($this->dir, $this->dir)))->load('.', 'rephp');
    }
}
