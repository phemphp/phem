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

namespace App;

use Phem\Test\TestKernel;
use Playwright\Symfony\PlaywrightSymfonyBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The app of the end-to-end tests. Playwright PHP sends the requests of the browser to this
 * kernel, in the process of PHPUnit: the app needs no web server.
 *
 * On boot, TestKernel compiles the `.phem` sources of src/ with phemc into var/phem/. Add a page
 * in src/Ui/ and its service below.
 */
final class Kernel extends TestKernel implements CompilerPassInterface
{
    public function registerBundles(): iterable
    {
        return [...parent::registerBundles(), new PlaywrightSymfonyBundle()];
    }

    protected function sourceDir(): string
    {
        return 'src';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->import($this->getProjectDir().'/config/*.yaml');

        $container->services()
            ->set(Database::class)
                ->args(['%kernel.project_dir%/var/e2e.sqlite'])
            ->set(Guestbook::class)
                ->args([service(Database::class)])
            // The feed of ChatRoom loads its messages again with their identifier (see Phem\Feed).
            ->set(Chat::class)
                ->args([service(Database::class)])
            ->alias('phem.entity_finder', Chat::class)
            ->alias('phem.object_identifier', Chat::class);
    }

    /**
     * In the tests, PhemBundle sends the streams to a MemoryPublisher (see TestPublisherPass). The
     * browsers of the e2e tests get them from the Mercure hub, so publish with its HTTP API.
     */
    public function process(ContainerBuilder $container): void
    {
        // PhemBundle registers it with phem.mercure.publisher_key (config/phem.yaml).
        if (!$container->hasDefinition('phem.stream_publisher.hub')) {
            throw new \LogicException('The e2e app publishes the streams with the HubPublisher of PhemBundle, but the "phem.stream_publisher.hub" service does not exist: check phem.mercure.publisher_key in config/phem.yaml, and the services of PhemBundle::loadExtension().');
        }

        $container->setAlias('phem.stream_publisher', 'phem.stream_publisher.hub');
    }
}
