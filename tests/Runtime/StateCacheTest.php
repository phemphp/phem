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

use Phem\PhemBundle;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

final class StateCacheTest extends TestCase
{
    use FixtureApp;

    public function testCacheFindsTheStateOfASignature(): void
    {
        $pool = new ArrayAdapter();
        $cache = new StateCache($pool);
        [$json, $signature] = new StateCodec('secret')->encode(['phem1' => ['n' => 1]], 'App\\A');
        $cache->save($signature, $json);

        self::assertSame([hash('xxh128', $signature)], array_keys($pool->getValues()), 'the key is a hash of the signature');
        self::assertSame($json, $cache->find($signature));
        self::assertNull($cache->find(new StateCodec('secret')->encode(['phem1' => ['n' => 2]], 'App\\A')[1]), 'another state');
    }

    /** @return iterable<string, array{string}> */
    public static function provideNoSignature(): iterable
    {
        yield 'empty' => [''];
        yield 'the HMAC alone' => [str_repeat('a', 64)];
        yield 'no time' => ['.'.str_repeat('a', 64)];
        yield 'a short HMAC' => ['1.'.str_repeat('a', 63)];
        yield 'upper case' => ['1.'.str_repeat('A', 64)];
        yield 'reserved characters of PSR-6' => ['1.{}()/\\@:'.str_repeat('a', 55)];
        yield 'a line break at the end' => ['1.'.str_repeat('a', 64)."\n"];
    }

    /** A value from the browser that is not a signature is not a key of the pool. */
    #[DataProvider('provideNoSignature')]
    public function testValueThatIsNotASignatureIsNotAKey(string $signature): void
    {
        $pool = new ArrayAdapter();
        $cache = new StateCache($pool);
        $cache->save($signature, '{}');

        self::assertNull($cache->find($signature));
        self::assertSame([], $pool->getValues());
    }

    public function testCacheWithNoPoolKeepsNothing(): void
    {
        $cache = new StateCache();
        $cache->save('1.'.str_repeat('a', 64), '{}');

        self::assertNull($cache->find('1.'.str_repeat('a', 64)));
    }

    public function testEntryThatIsNotAStringIsNotFound(): void
    {
        $pool = new ArrayAdapter();
        $signature = '1.'.str_repeat('a', 64);
        $pool->save($pool->getItem(hash('xxh128', $signature))->set(['a' => 1]));

        self::assertNull(new StateCache($pool)->find($signature));
    }

    public function testBundleGivesThePoolToTheCache(): void
    {
        $argument = $this->load([])->getDefinition('phem.state_cache')->getArgument(0);

        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame(StateCache::POOL, (string) $argument);
        self::assertNull($this->load(['state_cache' => false])->getDefinition('phem.state_cache')->getArgument(0), 'the option turns the cache off');
    }

    public function testBundlePrependsThePool(): void
    {
        $builder = new ContainerBuilder();
        $extension = new PhemBundle()->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $extension->prepend($builder);

        $pools = array_merge(...array_map(static fn (array $c): array => $c['cache']['pools'] ?? [], $builder->getExtensionConfig('framework')));
        self::assertSame([StateCache::POOL => ['adapter' => 'cache.app', 'default_lifetime' => 3600]], $pools);
    }

    public function testAppHasThePool(): void
    {
        $pool = $this->service(StateCache::POOL, CacheItemPoolInterface::class);
        $pool->save($pool->getItem('a')->set('{}'));

        self::assertTrue($pool->hasItem('a'));
        $pool->clear();
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.bundles', []);
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());

        $extension = new PhemBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $builder);

        return $builder;
    }
}
