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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TransactionalOptionTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool|null, bool}>
     */
    public static function provideOption(): iterable
    {
        yield 'Doctrine, no option' => [true, null, true];
        yield 'Doctrine, option off' => [true, false, false];
        yield 'Doctrine, option on' => [true, true, true];
        yield 'no Doctrine, no option' => [false, null, false];
        yield 'no Doctrine, option off' => [false, false, false];
    }

    #[DataProvider('provideOption')]
    public function testOptionSetsTheMiddleware(bool $doctrine, ?bool $option, bool $all): void
    {
        $builder = $this->load($doctrine, $option);

        self::assertSame($all, $builder->getDefinition('phem.handler_middleware.transactional')->getArgument(1));
        self::assertSame($doctrine, $builder->hasDefinition('phem.transaction_runner'));
    }

    public function testOptionNeedsDoctrine(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('install doctrine/orm');

        $this->load(false, true);
    }

    private function load(bool $doctrine, ?bool $option): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.bundles', $doctrine ? ['DoctrineBundle' => 'Doctrine\Bundle\DoctrineBundle\DoctrineBundle'] : []);
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());

        $extension = new PhemBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([null === $option ? [] : ['transactional' => $option]], $builder);

        return $builder;
    }
}
