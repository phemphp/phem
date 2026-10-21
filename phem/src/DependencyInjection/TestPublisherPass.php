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

namespace Phem\DependencyInjection;

use Phem\Stream\MemoryPublisher;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * In the tests (`framework.test: true`), the streams go to a MemoryPublisher: a test runs with
 * no Mercure hub, and a ComponentTester gets the updates of its page (see
 * Phem\Test\ComponentTester::receive()).
 */
final class TestPublisherPass implements CompilerPassInterface
{
    /** The service of the MemoryPublisher. A decorator (the profiler) does not hide it. */
    public const ID = 'phem.test.stream_publisher';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('test.service_container') || !$container->hasDefinition('phem.stream_publisher')) {
            return;
        }
        $container->setDefinition(self::ID, new Definition(MemoryPublisher::class))->setPublic(true);
        $container->removeDefinition('phem.stream_publisher');
        $container->setAlias('phem.stream_publisher', self::ID);
    }
}
