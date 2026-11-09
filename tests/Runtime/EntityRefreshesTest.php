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
use Phem\Metadata\ComponentMetadata;
use Phem\Path\EntityFinder;
use Phem\Rendering\Fiber;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\Routing\PageRouteValues;
use Phem\Stream\BroadcastKeys;
use Phem\Stream\EntityChange;
use Phem\Stream\EntityRefreshes;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\PageStreams;
use Phem\Stream\PathTopicValues;
use Phem\Stream\Streams;
use Phem\Stream\Topics;
use Phem\Tests\Fixtures\BroadcastFieldsNotAList;
use Phem\Tests\Fixtures\EntitiesOnAHandler;
use Phem\Tests\Fixtures\Shop\ShopOrder;
use Phem\Tests\Fixtures\Shop\ShopOrderLine;
use Phem\Tests\Fixtures\Shop\ShopTag;
use Phem\Tests\Fixtures\ShopAlerts;
use Phem\Tests\Fixtures\ShopLineItem;
use Phem\Tests\Fixtures\ShopOrderBadge;
use Phem\Tests\Fixtures\ShopOrderPage;
use Phem\Tests\Fixtures\ShopOrdersPage;
use Phem\Tests\Fixtures\ShopTwoPathPage;
use Phem\Tests\Fixtures\UnknownBroadcastEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

require_once \dirname(__DIR__).'/Fixtures/EntityBroadcasting.php';
require_once \dirname(__DIR__).'/Fixtures/InvalidBroadcasts.php';

final class EntityRefreshesTest extends TestCase
{
    private const BROADCASTERS = [ShopOrderPage::class, ShopOrdersPage::class, ShopTwoPathPage::class, ShopAlerts::class, ShopLineItem::class, ShopOrderBadge::class];

    private Topics $topics;
    private BroadcastKeys $keys;
    private Streams $streams;
    private MemoryPublisher $publisher;
    private EntityRefreshes $refreshes;

    protected function setUp(): void
    {
        $metadata = new ComponentMetadata();
        $this->topics = new Topics($metadata, 's3cret', values: new PathTopicValues($metadata, self::identifier(), self::finder()));
        $this->keys = new BroadcastKeys('s3cret', self::identifier(), $metadata);
        $this->publisher = new MemoryPublisher();
        $this->streams = new Streams(new Renderer(new Manifest(sys_get_temp_dir().'/phem-no-build'), new MarkupResolver(new Environment(new ArrayLoader())), []), $this->publisher, $this->topics, false, $this->keys);
        $this->refreshes = new EntityRefreshes($this->streams, $this->topics, $this->keys, self::BROADCASTERS, $metadata);
    }

    public function testAnEntityRefreshesTheUrlOfItsPathPropAndTheInstancesOfItsKeyProp(): void
    {
        $order = self::order(42);

        $targets = $this->refreshes->of(new EntityChange($order, true, ['note']));

        self::assertEqualsCanonicalizing([
            $this->page(ShopOrderPage::class, 'order', $order),
            // No entity prop: all the URLs.
            $this->allUrls(ShopOrdersPage::class),
            $this->instances(ShopOrderBadge::class, 'order', $order),
            // ShopAlerts refreshes for its fields only, ShopTwoPathPage for a line only.
        ], $this->describe($targets));
    }

    public function testARelatedEntitySelectsTheUrlAndTheInstancesOfItsAssociation(): void
    {
        $order = self::order(42);
        $line = self::line(7, $order);

        $targets = $this->refreshes->of(new EntityChange($line, true, ['quantity'], [$order]));

        self::assertEqualsCanonicalizing([
            $this->page(ShopOrderPage::class, 'order', $order),
            // The other #[Path] prop has no entity: the topic of the order prop alone.
            $this->page(ShopTwoPathPage::class, 'order', $order),
            $this->allInstances(ShopAlerts::class),
            $this->instances(ShopLineItem::class, 'line', $line),
        ], $this->describe($targets));
    }

    public function testAFieldListRefreshesAfterAnUpdateOfTheseFieldsOnly(): void
    {
        $order = self::order(42);
        $alerts = $this->allInstances(ShopAlerts::class);

        self::assertNotContains($alerts, $this->describe($this->refreshes->of(new EntityChange($order, true, ['note']))));
        self::assertContains($alerts, $this->describe($this->refreshes->of(new EntityChange($order, true, ['status']))));
        self::assertContains($alerts, $this->describe($this->refreshes->of(new EntityChange($order, true, ['tags']))), 'a collection');
        self::assertContains($alerts, $this->describe($this->refreshes->of(new EntityChange($order))), 'an insert or a delete');
    }

    public function testAProxyIsAnInstanceOfItsEntity(): void
    {
        $proxy = new class extends ShopOrder {
        };
        $proxy->id = 42;

        self::assertSame($this->describe($this->refreshes->of(new EntityChange(self::order(42)))), $this->describe($this->refreshes->of(new EntityChange($proxy))));
    }

    public function testAnEntityOfNoBroadcasterRefreshesNothing(): void
    {
        self::assertSame([], $this->refreshes->of(new EntityChange(new \stdClass())));
    }

    public function testTheSameRefreshOfATopicGoesOutOnce(): void
    {
        $order = self::order(42);
        foreach ([...$this->refreshes->of(new EntityChange($order)), ...$this->refreshes->of(new EntityChange($order, true, ['status']))] as $target) {
            $target->refresh();
        }

        // The handler of the badge refreshes it too.
        $this->streams->toComponent(ShopOrderBadge::class, ['order' => $order, 'size' => 'md'])->refresh();
        $this->streams->toComponent(ShopOrderBadge::class, ['order' => $order, 'size' => 'md'])->refresh();
        $this->streams->flush();

        $updates = $this->publisher->updates();
        self::assertCount(4, $updates, 'one update for each topic');
        foreach ($updates as $update) {
            $streams = substr_count($update['data'], '<turbo-stream');
            self::assertSame($update['topics'] === [$this->topics->toComponent(ShopOrderBadge::class)] ? 2 : 1, $streams, $this->topics->name($update['topics'][0]));
        }
    }

    public function testAParameterOfAFunctionPageThatIsNotARouteValueSelectsNoUrl(): void
    {
        $page = 'Phem\\Tests\\Fixtures\\ShopOrderFunctionPage';
        $routes = new RouteCollection();
        $routes->add('order', new Route('/orders/{order}', ['_phem_page' => $page]));
        $metadata = new ComponentMetadata();
        $topics = new Topics($metadata, 's3cret', new PageRouteValues(new Router(new ClosureLoader(), static fn (): RouteCollection => $routes)), new PathTopicValues($metadata, self::identifier(), self::finder()));
        $refreshes = new EntityRefreshes($this->streams, $topics, $this->keys, [$page], $metadata);

        $targets = $refreshes->of(new EntityChange(new ShopTag('urgent')));

        // The tag is not in the URL: all the URLs of the page.
        self::assertSame([[$topics->name($topics->toPage($page)), null]], array_map(static fn (PageStreams $target): array => [$topics->name($target->topic), $target->key], $targets));
    }

    public function testAnEntityKeySelectsTheInstancesOfAComponentWithMoreKeyProps(): void
    {
        $order = self::order(42);
        $badge = new ShopOrderBadge();
        $badge->order = $order;
        $badge->size = 'lg';
        $fiber = new Fiber('phem0123456789ab', '/c', ShopOrderBadge::class);
        $fiber->instance = $badge;

        $keys = $this->keys->ofFiber($fiber);

        self::assertContains($this->keys->ofProp(ShopOrderBadge::class, 'order', $order), $keys);
        self::assertContains($this->keys->ofInstance(ShopOrderBadge::class, ['order' => $order, 'size' => 'lg']), $keys);
        self::assertCount(3, $keys);
    }

    public function testAComponentWithOneKeyPropHasNoOtherKey(): void
    {
        $line = self::line(7, self::order(42));
        $item = new ShopLineItem();
        $item->line = $line;
        $fiber = new Fiber('phem0123456789ab', '/c', ShopLineItem::class);
        $fiber->instance = $item;

        self::assertSame([$this->keys->ofComponent(ShopLineItem::class), $this->keys->ofProp(ShopLineItem::class, 'line', $line)], $this->keys->ofFiber($fiber));
    }

    public function testTheEntitiesOfOnHaveTheirFields(): void
    {
        self::assertSame([ShopOrder::class => ['status', 'tags'], ShopOrderLine::class => []], new ComponentMetadata()->entityBroadcasts(ShopAlerts::class));
        self::assertSame([ShopOrder::class => []], new ComponentMetadata()->entityBroadcasts(ShopOrdersPage::class));
        self::assertSame([], new ComponentMetadata()->entityBroadcasts(\stdClass::class));
    }

    public function testAHandlerHasNoEntities(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The method "'.EntitiesOnAHandler::class.'::save()" has #[Broadcast(on: ...)]: only a page or a component can have the "on" argument.');

        new ComponentMetadata()->broadcasts(EntitiesOnAHandler::class);
    }

    public function testAnEntityOfOnExists(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"'.UnknownBroadcastEntity::class.'" has #[Broadcast(on: ...)] with the class "App\NoSuchEntity", which does not exist.');

        new ComponentMetadata()->entityBroadcasts(UnknownBroadcastEntity::class);
    }

    public function testTheFieldsOfAnEntityAreAList(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "on" argument of #[Broadcast] is a class, a list of classes, or a class with a list of its fields');

        new ComponentMetadata()->entityBroadcasts(BroadcastFieldsNotAList::class);
    }

    /** @return array{0: string, 1: ?string} */
    private function page(string $page, string $prop, object $entity): array
    {
        return [$this->topics->name($this->topics->toPageProp($page, $prop, $entity)), null];
    }

    /** @return array{0: string, 1: ?string} */
    private function allUrls(string $page): array
    {
        return [$this->topics->name($this->topics->toPage($page)), null];
    }

    /** @return array{0: string, 1: ?string} */
    private function instances(string $component, string $prop, object $entity): array
    {
        return [$this->topics->name($this->topics->toComponent($component)), $this->keys->ofProp($component, $prop, $entity)];
    }

    /** @return array{0: string, 1: ?string} */
    private function allInstances(string $component): array
    {
        return [$this->topics->name($this->topics->toComponent($component)), $this->keys->ofComponent($component)];
    }

    /**
     * @param list<PageStreams> $targets
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function describe(array $targets): array
    {
        return array_map(fn (PageStreams $target): array => [$this->topics->name($target->topic), $target->key], $targets);
    }

    private static function order(int $id): ShopOrder
    {
        $order = new ShopOrder();
        $order->id = $id;

        return $order;
    }

    private static function line(int $id, ShopOrder $order): ShopOrderLine
    {
        $line = new ShopOrderLine($order);
        $line->id = $id;

        return $line;
    }

    private static function identifier(): ObjectIdentifier
    {
        return new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                return $value instanceof ShopOrder || $value instanceof ShopOrderLine ? ['id' => $value->id] : null;
            }
        };
    }

    private static function finder(): EntityFinder
    {
        return new class implements EntityFinder {
            public function supports(string $class): bool
            {
                return is_a($class, ShopOrder::class, true) || is_a($class, ShopOrderLine::class, true);
            }

            public function find(string $class, mixed $id): ?object
            {
                return null;
            }
        };
    }
}
