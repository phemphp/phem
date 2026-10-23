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

use Phem\Async\MemoryBus;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * In the tests (`framework.test: true`), a MemoryBus decorates the Messenger bus of the #[Async]
 * handlers. A page of InteractsWithPhem::visit() keeps the messages in it: the test runs with no
 * worker, and a ComponentTester runs the work when the test asks for it (see
 * Phem\Test\ComponentTester::settle()). Another test (a WebTestCase, Panther) uses the Messenger
 * configuration of the application.
 */
final class TestAsyncPass implements CompilerPassInterface
{
    /** The service of the MemoryBus. */
    public const ID = 'phem.test.async_bus';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('test.service_container') || !$container->hasDefinition('phem.async.dispatcher') || !$container->has('messenger.default_bus')) {
            return;
        }
        $container->setDefinition(self::ID, new Definition(MemoryBus::class, [new Reference('messenger.default_bus')]))->setPublic(true);
        $container->getDefinition('phem.async.dispatcher')->replaceArgument(0, new Reference(self::ID));
    }
}
