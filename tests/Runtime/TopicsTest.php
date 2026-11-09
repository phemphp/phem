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
use Phem\Metadata\ComponentMetadata;
use Phem\Path\EntityFinder;
use Phem\Routing\PageRouteValues;
use Phem\Stream\PathTopicValues;
use Phem\Stream\Topics;
use Phem\Tests\Fixtures\Shop\ShopOrder;
use Phem\Tests\Fixtures\Shop\ShopOrderLine;
use Phem\Tests\Fixtures\ShopLinePage;
use Phem\Tests\Fixtures\ShopOrderPage;
use Phem\Tests\Fixtures\ShopTwoPathPage;
use Phem\Tests\Fixtures\Scientist;
use Phem\Tests\Fixtures\Size;
use Phem\Tests\Fixtures\Broadcasting;
use Phem\Tests\Fixtures\BroadcastingComponent;
use Phem\Tests\Fixtures\KeyedBroadcastingComponent;
use Phem\Tests\Fixtures\KeyedBroadcastingReplacement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

require_once \dirname(__DIR__).'/Fixtures/BroadcastingPages.php';
require_once \dirname(__DIR__).'/Fixtures/EntityBroadcasting.php';

final class TopicsTest extends TestCase
{
    public function testAPageSubscribesToItselfToItsUrlAndToAllPages(): void
    {
        $topics = new Topics(secret: 's3cret');
        $page = $topics->ofPage(Broadcasting::class, ['size' => 'l', 'id' => '42']);

        self::assertSame(['phem:'.Broadcasting::class, 'phem:'.Broadcasting::class.'?id=42&size=l', 'phem:*'], array_map($topics->name(...), $page));
    }

    public function testATopicIsAnHmacOfItsName(): void
    {
        [$topic, $scoped] = new Topics(secret: 's3cret')->ofPage(Broadcasting::class, ['id' => '42', 'size' => 'l']);

        self::assertMatchesRegularExpression('/^phem:[A-Za-z0-9_-]{22}$/', $topic);
        self::assertStringNotContainsString('Broadcasting', $topic.$scoped);
        self::assertStringNotContainsString('42', $scoped);
        self::assertNotSame($topic, new Topics(secret: 'other')->ofPage(Broadcasting::class, [])[0], 'another secret');
        self::assertSame($topic, new Topics(secret: 's3cret')->ofPage(Broadcasting::class, [])[0], 'the next request');
    }

    public function testAPageWithNoRouteValuesHasNoTopicOfAUrl(): void
    {
        $topics = new Topics();

        self::assertSame(['phem:'.Broadcasting::class, 'phem:*'], array_map($topics->name(...), $topics->ofPage(Broadcasting::class, [])));
    }

    public function testAPageWithNoBroadcastHasNoTopics(): void
    {
        self::assertSame([], new Topics()->ofPage(Scientist::class, ['id' => '1']));
    }

    public function testThePublisherGetsTheTopicOfThePageRender(): void
    {
        $topics = new Topics();

        self::assertSame($topics->ofPage(Broadcasting::class, [])[0], $topics->toPage('\\'.Broadcasting::class));
        // An int and a backed enum are the strings of the URL.
        self::assertSame($topics->ofPage(Broadcasting::class, ['id' => '42', 'size' => 'l'])[1], $topics->toPage(Broadcasting::class, ['size' => Size::Large, 'id' => 42]));
    }

    public function testThePublisherNeedsAPageWithBroadcast(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no #[Phem\Attribute\Broadcast] attribute');

        new Topics()->toPage(Scientist::class);
    }

    public function testThePublisherNeedsAllTheRouteValues(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('need all its route values, but "size" is missing');

        new Topics()->toPage(Broadcasting::class, ['id' => 42]);
    }

    public function testThePublisherNeedsTheRouteValuesOfThePage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no #[Path] prop "slug": give the route values of its #[Path] props ("id", "size")');

        new Topics()->toPage(Broadcasting::class, ['slug' => 'x']);
    }

    public function testAFunctionPageSubscribes(): void
    {
        $topics = new Topics();
        $page = 'Phem\Tests\Fixtures\BroadcastingPage';

        self::assertSame(['phem:'.$page, 'phem:'.$page.'?id=42', 'phem:*'], array_map($topics->name(...), $topics->ofPage($page, ['id' => '42'])));
        self::assertSame([], $topics->ofPage('Phem\Tests\Fixtures\NotBroadcastingPage', ['id' => '42']));
    }

    public function testThePublisherGetsTheRouteValuesOfAFunctionPageFromItsRoutes(): void
    {
        $topics = self::routed();
        $page = 'Phem\Tests\Fixtures\BroadcastingPage';

        // The Request and the service are parameters, but not route values.
        self::assertSame($topics->ofPage($page, ['id' => '42', 'tab' => 'all'])[1], $topics->toPage($page, ['tab' => 'all', 'id' => 42]));
    }

    public function testThePublisherNeedsAllTheRouteValuesOfAFunctionPage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('need all its route values, but "tab" is missing');

        self::routed()->toPage('Phem\Tests\Fixtures\BroadcastingPage', ['id' => 42]);
    }

    public function testAParameterOfAFunctionPageThatIsNotARouteValueIsUnknown(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no route value parameter "guestbook": give the route values of its route value parameters ("id", "tab")');

        self::routed()->toPage('Phem\Tests\Fixtures\BroadcastingPage', ['id' => 42, 'guestbook' => 'x']);
    }

    public function testAPageSubscribesToItsComponentsWithBroadcast(): void
    {
        $topics = new Topics();
        $widget = 'Phem\Tests\Fixtures\BroadcastingWidget';
        $components = $topics->components([Broadcasting::class, BroadcastingComponent::class, Scientist::class, $widget, BroadcastingComponent::class]);

        // The page is not a component, and a component without #[Broadcast] has no topic.
        self::assertSame([BroadcastingComponent::class, $widget], $components);
        self::assertSame(['phem:'.Broadcasting::class, 'phem:*', 'phem:'.BroadcastingComponent::class, 'phem:'.$widget], array_map($topics->name(...), $topics->ofPage(Broadcasting::class, [], $components)));
        self::assertSame(['phem:'.BroadcastingComponent::class], array_map($topics->name(...), $topics->ofPage(Scientist::class, [], [BroadcastingComponent::class])), 'a page with no #[Broadcast]');
    }

    public function testAReplacementSubscribesToTheComponentThatItReplaces(): void
    {
        $components = new Topics()->components([KeyedBroadcastingReplacement::class]);

        self::assertSame([KeyedBroadcastingComponent::class], $components);
    }

    public function testThePublisherGetsTheTopicOfAComponent(): void
    {
        $topics = new Topics();

        self::assertSame($topics->ofPage(Scientist::class, [], [BroadcastingComponent::class])[0], $topics->toComponent(BroadcastingComponent::class));
        self::assertSame($topics->ofPage(Scientist::class, [], ['Phem\Tests\Fixtures\BroadcastingWidget'])[0], $topics->toComponent('Phem\Tests\Fixtures\BroadcastingWidget'));
    }

    public function testAComponentIsNotAPage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Phem\Tests\Fixtures\BroadcastingComponent" is a component, not a page: use Streams::toComponent().');

        new Topics()->toPage(BroadcastingComponent::class);
    }

    public function testAPageIsNotAComponent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Phem\Tests\Fixtures\Broadcasting" is not a component (a #[Component] with no #[Page]): use Streams::toPage() for a page.');

        new Topics()->toComponent(Broadcasting::class);
    }

    public function testThePublisherNeedsAComponentWithBroadcast(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The streams go to the components with #[Broadcast], but "Phem\Tests\Fixtures\Scientist" has no #[Phem\Attribute\Broadcast] attribute on it or on a handler.');

        new Topics()->toComponent(Scientist::class);
    }

    public function testARouteValueIsNotAnObjectWithNoIdentifier(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The route value "id" of a topic is a "stdClass": give an entity, or the value of the URL.');

        new Topics()->toPage(Broadcasting::class, ['id' => new \stdClass(), 'size' => 'l']);
    }

    public function testAnEntityPropIsTheIdentifierOfItsEntityInAllTheRoutes(): void
    {
        $topics = self::withEntities();
        $order = self::order(42);

        $url = 'phem:'.ShopOrderPage::class.'?order%5Bid%5D=42';
        // `{order}`, `{id:order}` and `{slug:order}`.
        foreach (['42', ['id' => '42'], ['slug' => 'a1b2']] as $value) {
            self::assertSame($url, $topics->name($topics->ofPage(ShopOrderPage::class, ['order' => $value], props: ['order' => $order])[1]));
        }

        self::assertSame($url, $topics->name($topics->toPage(ShopOrderPage::class, ['order' => $order])));
    }

    public function testThePublisherGivesTheEntityOfAnEntityProp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[Path] prop "order" of "'.ShopOrderPage::class.'" is an entity: give the ShopOrder (or a reference: $entityManager->getReference(ShopOrder::class, …)), not int.');

        self::withEntities()->toPage(ShopOrderPage::class, ['order' => 42]);
    }

    public function testAnObjectWithNoIdentifierKeepsItsRouteValue(): void
    {
        $topics = self::withEntities();

        self::assertSame('phem:'.ShopOrderPage::class.'?order=42', $topics->name($topics->ofPage(ShopOrderPage::class, ['order' => '42'], props: ['order' => new \stdClass()])[1]));
    }

    public function testAPageWithEntitiesAndMoreRouteValuesSubscribesToEachEntity(): void
    {
        $topics = self::withEntities();
        $order = self::order(42);

        $page = array_map($topics->name(...), $topics->ofPage(ShopTwoPathPage::class, ['shop' => '1', 'order' => '42'], props: ['shop' => 1, 'order' => $order]));

        self::assertSame([
            'phem:'.ShopTwoPathPage::class,
            'phem:'.ShopTwoPathPage::class.'?order%5Bid%5D=42&shop=1',
            'phem:'.ShopTwoPathPage::class.'?order%5Bid%5D=42',
            'phem:*',
        ], $page);
        self::assertSame($page[2], $topics->name($topics->toPageProp(ShopTwoPathPage::class, 'order', $order)));
    }

    public function testAPageWithOneEntityPropHasNoOtherTopic(): void
    {
        $topics = self::withEntities();

        self::assertCount(3, $topics->ofPage(ShopOrderPage::class, ['order' => '42'], props: ['order' => self::order(42)]));
        self::assertSame($topics->toPage(ShopOrderPage::class, ['order' => self::order(42)]), $topics->toPageProp(ShopOrderPage::class, 'order', self::order(42)));
    }

    public function testAnEntityInAnIdentifierIsItsIdentifier(): void
    {
        $identifier = new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                return match (true) {
                    $value instanceof ShopOrder => ['id' => $value->id],
                    // A derived identity: the order and a number.
                    $value instanceof ShopOrderLine => ['order' => $value->order, 'n' => $value->quantity],
                    default => null,
                };
            }
        };
        $topics = new Topics(values: new PathTopicValues(new ComponentMetadata(), $identifier));
        $line = new ShopOrderLine(self::order(42), 3);

        $url = 'phem:'.ShopLinePage::class.'?line%5Bn%5D=3&line%5Border%5D%5Bid%5D=42';
        self::assertSame($url, $topics->name($topics->ofPage(ShopLinePage::class, ['line' => '42-3'], props: ['line' => $line])[1]));
        self::assertSame($url, $topics->name($topics->toPage(ShopLinePage::class, ['line' => $line])));
    }

    public function testThePublisherGivesAnEntityWithAnIdentifier(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The #[Path] prop "order" of "'.ShopOrderPage::class.'" gets a ShopOrder with no identifier yet: refresh its page after the flush.');

        self::withEntities()->toPage(ShopOrderPage::class, ['order' => new ShopOrder()]);
    }

    private static function withEntities(): Topics
    {
        $identifier = new class implements ObjectIdentifier {
            public function identify(object $value): ?array
            {
                // As Doctrine: no values for an entity with no identifier yet.
                return $value instanceof ShopOrder ? array_filter(['id' => $value->id], static fn (?int $id): bool => null !== $id) : null;
            }
        };
        $finder = new class implements EntityFinder {
            public function supports(string $class): bool
            {
                return is_a($class, ShopOrder::class, true);
            }

            public function find(string $class, mixed $id): ?object
            {
                return null;
            }
        };

        return new Topics(values: new PathTopicValues(new ComponentMetadata(), $identifier, $finder));
    }

    private static function order(int $id): ShopOrder
    {
        $order = new ShopOrder();
        $order->id = $id;

        return $order;
    }

    private static function routed(): Topics
    {
        $routes = new RouteCollection();
        $routes->add('board', new Route('/board/{id}/{tab}', ['_phem_page' => 'Phem\Tests\Fixtures\BroadcastingPage']));

        return new Topics(routes: new PageRouteValues(new Router(new ClosureLoader(), static fn (): RouteCollection => $routes)));
    }
}
