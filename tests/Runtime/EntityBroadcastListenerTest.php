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

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Phem\Doctrine\DoctrineEntityFinder;
use Phem\Doctrine\DoctrineObjectIdentifier;
use Phem\Doctrine\EntityBroadcastListener;
use Phem\Manifest\Manifest;
use Phem\Metadata\ComponentMetadata;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\Stream\BroadcastKeys;
use Phem\Stream\EntityRefreshes;
use Phem\Stream\MemoryPublisher;
use Phem\Stream\PathTopicValues;
use Phem\Stream\SenderToken;
use Phem\Stream\Streams;
use Phem\Stream\Topics;
use Phem\Tests\Fixtures\Shop\ShopOrder;
use Phem\Tests\Fixtures\Shop\ShopOrderLine;
use Phem\Tests\Fixtures\Shop\ShopTag;
use Phem\Tests\Fixtures\ShopAlerts;
use Phem\Tests\Fixtures\ShopLineItem;
use Phem\Tests\Fixtures\ShopOrderBadge;
use Phem\Tests\Fixtures\ShopOrderPage;
use Phem\Tests\Fixtures\ShopOrdersPage;
use Phem\Tests\Fixtures\ShopTwoPathPage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

require_once \dirname(__DIR__).'/Fixtures/EntityBroadcasting.php';

#[RequiresPhpExtension('pdo_sqlite')]
final class EntityBroadcastListenerTest extends TestCase
{
    private EntityManager $manager;
    private Topics $topics;
    private BroadcastKeys $keys;
    private Streams $streams;
    private MemoryPublisher $publisher;
    private ManagerRegistry $doctrine;
    private SenderToken $sender;
    private int $seen = 0;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__).'/Fixtures/Shop'], true);
        $config->enableNativeLazyObjects(true);
        $this->manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        new SchemaTool($this->manager)->createSchema($this->manager->getMetadataFactory()->getAllMetadata());

        $this->doctrine = $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturnCallback(fn (string $class): ?EntityManager => str_starts_with($class, 'Phem\\Tests\\Fixtures\\Shop\\') ? $this->manager : null);

        $metadata = new ComponentMetadata();
        $identifier = new DoctrineObjectIdentifier($doctrine);
        $this->topics = new Topics($metadata, 's3cret', values: new PathTopicValues($metadata, $identifier, new DoctrineEntityFinder($doctrine)));
        $this->keys = new BroadcastKeys('s3cret', $identifier, $metadata);
        $this->publisher = new MemoryPublisher();
        $this->sender = new SenderToken();
        $this->streams = new Streams(new Renderer(new Manifest(sys_get_temp_dir().'/phem-no-build'), new MarkupResolver(new Environment(new ArrayLoader())), []), $this->publisher, $this->topics, false, $this->keys, $this->sender);

        $broadcasters = [ShopOrderPage::class, ShopOrdersPage::class, ShopTwoPathPage::class, ShopAlerts::class, ShopLineItem::class, ShopOrderBadge::class];
        $listener = new EntityBroadcastListener(new EntityRefreshes($this->streams, $this->topics, $this->keys, $broadcasters, $metadata), $doctrine, $this->sender);
        $this->manager->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], $listener);
    }

    public function testAnInsertRefreshesWithTheNewIdentifier(): void
    {
        $order = new ShopOrder();
        $this->manager->persist($order);
        $this->manager->flush();

        self::assertNotNull($order->id);
        self::assertEqualsCanonicalizing([
            ShopOrderPage::class.'?order%5Bid%5D='.$order->id,
            ShopOrdersPage::class,
            ShopAlerts::class,
            ShopOrderBadge::class,
        ], $this->refreshed());
    }

    public function testAnUpdateRefreshesForTheFieldsOfOn(): void
    {
        $order = $this->saved(new ShopOrder());

        $order->note = 'Ring twice';
        $this->manager->flush();

        self::assertNotContains(ShopAlerts::class, $this->refreshed(), 'not a field of ShopAlerts');

        $order->status = 'shipped';
        $this->manager->flush();

        self::assertContains(ShopAlerts::class, $this->refreshed());
    }

    public function testAChangeOfACollectionIsAChangeOfItsField(): void
    {
        $order = $this->saved(new ShopOrder());
        $tag = $this->saved(new ShopTag('urgent'));

        $order->tags->add($tag);
        $this->manager->flush();

        self::assertContains(ShopAlerts::class, $this->refreshed());
    }

    public function testALineRefreshesThePageOfItsOrder(): void
    {
        $order = $this->saved(new ShopOrder());
        $line = $this->saved(new ShopOrderLine($order));

        $line->quantity = 3;
        $this->manager->flush();

        self::assertEqualsCanonicalizing([
            ShopOrderPage::class.'?order%5Bid%5D='.$order->id,
            ShopTwoPathPage::class.'?order%5Bid%5D='.$order->id,
            ShopAlerts::class,
            ShopLineItem::class,
        ], $this->refreshed());
    }

    public function testALineThatMovesRefreshesTheOldAndTheNewOrder(): void
    {
        $old = $this->saved(new ShopOrder());
        $new = $this->saved(new ShopOrder());
        $line = $this->saved(new ShopOrderLine($old));

        $line->order = $new;
        $this->manager->flush();

        $refreshed = $this->refreshed();
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$old->id, $refreshed);
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$new->id, $refreshed);
    }

    public function testADeleteRefreshesWithTheOldIdentifier(): void
    {
        $order = $this->saved(new ShopOrder());
        $id = $order->id;

        $this->manager->remove($order);
        $this->manager->flush();

        self::assertNull($order->id, 'the ORM removes a generated identifier');
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$id, $this->refreshed());
    }

    public function testAFlushThatThrowsRefreshesNothing(): void
    {
        $order = $this->saved(new ShopOrder());

        $order->status = 'shipped';
        $this->manager->getConnection()->executeStatement('DROP TABLE shop_orders');

        try {
            $this->manager->flush();
            self::fail('The flush throws.');
        } catch (\Throwable) {
        }

        self::assertSame([], $this->refreshed());
    }

    public function testALineThatMovesToANewOrderRefreshesTheOldOrder(): void
    {
        $old = $this->saved(new ShopOrder());
        $line = $this->saved(new ShopOrderLine($old));

        $line->order = new ShopOrder();
        $this->manager->persist($line->order);
        $this->manager->flush();

        $refreshed = $this->refreshed();
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$old->id, $refreshed);
        // The new order has no identifier in onFlush: its insert refreshes it after the flush.
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$line->order->id, $refreshed);
        self::assertNotContains(ShopOrderPage::class.'?', $refreshed);
    }

    public function testAFlushInAFlushKeepsTheRefreshesOfBoth(): void
    {
        $order = $this->saved(new ShopOrder());
        $other = $this->saved(new ShopOrder());

        // A postFlush listener before Phem's flushes again (an audit log).
        $nested = new class($this->manager, $other) {
            private bool $done = false;

            public function __construct(private readonly EntityManager $manager, private readonly ShopOrder $other)
            {
            }

            public function postFlush(): void
            {
                if (!$this->done) {
                    $this->done = true;
                    $this->other->note = 'Audited';
                    $this->manager->flush();
                }
            }
        };
        $events = $this->manager->getEventManager();
        $listeners = $events->getListeners(Events::postFlush);
        foreach ($listeners as $listener) {
            $events->removeEventListener([Events::postFlush], $listener);
        }

        $events->addEventListener([Events::postFlush], $nested);
        foreach ($listeners as $listener) {
            $events->addEventListener([Events::postFlush], $listener);
        }

        $order->note = 'Ring twice';
        $this->manager->flush();

        $refreshed = $this->refreshed();
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$order->id, $refreshed);
        self::assertContains(ShopOrderPage::class.'?order%5Bid%5D='.$other->id, $refreshed);
    }

    public function testAFlushInThePushRefreshOfABrowserRefreshesNothing(): void
    {
        $order = $this->saved(new ShopOrder());

        $this->sender->startEvent(refresh: true);
        $order->note = 'Seen';
        $this->manager->flush();
        $this->sender->endEvent();

        self::assertSame([], $this->refreshed());
    }

    public function testAFieldOfOnIsNotTheInverseSideOfAnAssociation(): void
    {
        $refreshes = new EntityRefreshes($this->streams, $this->topics, $this->keys, ['Phem\Tests\Fixtures\BroadcastingComponentOfAnInverseSide']);
        $this->manager->getEventManager()->addEventListener([Events::onFlush], new EntityBroadcastListener($refreshes, $this->doctrine));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('with the field "lines" of "'.ShopOrder::class.'", but it is the inverse side of an association');

        $this->manager->persist(new ShopOrder());
        $this->manager->flush();
    }

    public function testAClassOfOnIsAnEntity(): void
    {
        $refreshes = new EntityRefreshes($this->streams, $this->topics, $this->keys, ['Phem\Tests\Fixtures\BroadcastingComponentOfAnObject']);
        $this->manager->getEventManager()->addEventListener([Events::onFlush], new EntityBroadcastListener($refreshes, $this->doctrine));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Phem\Tests\Fixtures\BroadcastingComponentOfAnObject" has #[Broadcast(on: ...)] with the class "ArrayObject", which is not a Doctrine entity.');

        $this->manager->persist(new ShopOrder());
        $this->manager->flush();
    }

    public function testAFieldOfOnIsAFieldOfTheEntity(): void
    {
        $refreshes = new EntityRefreshes($this->streams, $this->topics, $this->keys, ['Phem\Tests\Fixtures\BroadcastingComponentOfAField']);
        $this->manager->getEventManager()->addEventListener([Events::onFlush], new EntityBroadcastListener($refreshes, $this->doctrine));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('with the field "colour" of "'.ShopOrder::class.'", but the entity has no such field or association.');

        $this->manager->persist(new ShopOrder());
        $this->manager->flush();
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function saved(object $entity): object
    {
        $this->manager->persist($entity);
        $this->manager->flush();
        $this->refreshed();

        return $entity;
    }

    /**
     * The names of the topics with a refresh since the last call: the page or the component, and
     * the route values of a page.
     *
     * @return list<string>
     */
    private function refreshed(): array
    {
        $this->streams->flush();
        $updates = \array_slice($this->publisher->updates(), $this->seen);
        $this->seen += \count($updates);

        $names = [];
        foreach ($updates as $update) {
            foreach ($update['topics'] as $topic) {
                $names[] = substr($this->topics->name($topic), \strlen('phem:'));
            }
        }

        return array_values(array_unique($names));
    }
}
