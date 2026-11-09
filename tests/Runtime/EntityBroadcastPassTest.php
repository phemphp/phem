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

use Phem\DependencyInjection\EntityBroadcastPass;
use Phem\Tests\Fixtures\ShopAlerts;
use Phem\Tests\Fixtures\ShopOrderPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

require_once \dirname(__DIR__).'/Fixtures/EntityBroadcasting.php';

final class EntityBroadcastPassTest extends TestCase
{
    private string $buildDir;

    protected function setUp(): void
    {
        $this->buildDir = sys_get_temp_dir().'/phem-entity-broadcast-pass-'.bin2hex(random_bytes(4));
        mkdir($this->buildDir);
        file_put_contents($this->buildDir.'/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->buildDir);
    }

    public function testThePassGivesTheBroadcastersWithEntities(): void
    {
        $container = $this->container([
            ShopAlerts::class => ['kind' => 'class'],
            'Phem\Tests\Fixtures\Broadcasting' => ['kind' => 'class'],
        ], [ShopOrderPage::class => '']);

        new EntityBroadcastPass()->process($container);

        self::assertEqualsCanonicalizing([ShopAlerts::class, ShopOrderPage::class], $container->getDefinition('phem.entity_refreshes')->getArgument(3));
        self::assertTrue($container->hasDefinition('phem.entity_broadcast_listener'));
    }

    public function testAChangeOfAFunctionRebuildsTheContainer(): void
    {
        $page = 'Phem\\Tests\\Fixtures\\ShopOrderFunctionPage';
        $container = $this->container([], [$page => '']);

        new EntityBroadcastPass()->process($container);

        $file = (string) new \ReflectionFunction($page)->getFileName();
        self::assertContains($file, array_map(static fn (ResourceInterface $resource): string => (string) $resource, $container->getResources()));
        self::assertSame([$page], $container->getDefinition('phem.entity_refreshes')->getArgument(3));
    }

    public function testThePassRemovesTheListenerWithNoBroadcasterWithEntities(): void
    {
        $container = $this->container(['Phem\Tests\Fixtures\Broadcasting' => ['kind' => 'class']], []);

        new EntityBroadcastPass()->process($container);

        self::assertFalse($container->hasDefinition('phem.entity_refreshes'));
        self::assertFalse($container->hasDefinition('phem.entity_broadcast_listener'));
    }

    /**
     * @param array<string, array<string, mixed>> $components
     * @param array<string, string>               $pages
     */
    private function container(array $components, array $pages): ContainerBuilder
    {
        file_put_contents($this->buildDir.'/manifest.php', '<?php return '.var_export(['components' => $components, 'pages' => $pages], true).';');

        $container = new ContainerBuilder();
        $container->setParameter('phem.build_dir', $this->buildDir);
        $container->setDefinition('phem.entity_refreshes', new Definition(\stdClass::class, [null, null, null, [], null]));
        $container->setDefinition('phem.entity_broadcast_listener', new Definition(\stdClass::class));

        return $container;
    }
}
