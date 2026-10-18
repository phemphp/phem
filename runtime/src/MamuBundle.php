<?php

/*
 * This file is part of the Mamu package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Mamu;

use Doctrine\ORM\EntityManagerInterface;
use Mamu\DependencyInjection\ComponentPass;
use Mamu\Handler\HandlerMiddleware;
use Mamu\Manifest\Manifest;
use Mamu\Path\PathValueConverter;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integrates `.phpm` components in a Symfony application.
 *
 *     # config/packages/mamu.yaml (all options are optional)
 *     mamu:
 *         source_dir: '%kernel.project_dir%/src'
 *         build_dir: '%kernel.project_dir%/var/cache/mamu'
 *         secret: '%kernel.secret%'
 *         state_max_age: 86400       # seconds; 0 for no limit
 *         state_bind_session: false
 *         transactional: ~           # true when Doctrine ORM is installed and enabled
 *
 *     # config/routes/mamu.yaml
 *     mamu:
 *         resource: .
 *         type: mamu
 *
 * A page is a class component with `#[Page]` (or `#[Route]`). It renders the document of the
 * application (`<html>`, `<head>`, `<body>`): a component that renders the page content in a
 * `<Root>` (see Mamu\Root). The client (the
 * `mamu` Stimulus controller and its CSS) is a Symfony UX package in assets/: with AssetMapper,
 * its files are in the `mamujs` namespace, and StimulusBundle loads the controller
 * from the `assets/controllers.json` of the application.
 *
 * Extension points (autoconfigured services):
 *
 * - a Mamu\Path\PathValueConverter converts the route values of other #[Path] prop types;
 * - a Mamu\Handler\HandlerMiddleware runs code around the event handlers;
 * - the `mamu.object_identifier` service (a Mamu\Handler\ObjectIdentifier) identifies the
 *   objects that the handlers capture (Doctrine entities, when DoctrineBundle is installed).
 */
final class MamuBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mamu';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('source_dir')
                    ->info('The .phpm sources (the directory that you pass to `mamuc build`)')
                    ->defaultValue('%kernel.project_dir%/src')
                ->end()
                ->scalarNode('build_dir')
                    ->info('The mamuc output (`mamuc build --out`)')
                    ->defaultValue('%kernel.project_dir%/var/cache/mamu')
                ->end()
                ->scalarNode('secret')
                    ->info('The key that signs the component state in the browser')
                    ->defaultValue('%kernel.secret%')
                ->end()
                ->integerNode('state_max_age')
                    ->info('The seconds that a signed state is valid (0 for no limit). An older state reloads the page.')
                    ->defaultValue(86400)
                    ->min(0)
                ->end()
                ->booleanNode('state_bind_session')
                    ->info('Sign the session id with the state, so the state of a session is not valid in another session. A login makes the open pages reload on their next event.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('transactional')
                    ->info('Run the event handlers of all components in a Doctrine ORM transaction. Set it to false to use #[Transactional] on each component instead. Default: true when Doctrine ORM is installed and enabled.')
                    ->defaultNull()
                ->end()
            ->end();
    }

    /**
     * @param array{source_dir: string, build_dir: string, secret: string, state_max_age: int, state_bind_session: bool, transactional: bool|null} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('mamu.source_dir', $config['source_dir'])
            ->set('mamu.build_dir', $config['build_dir']);
        $container->import('../config/services.php');
        $container->services()->get('mamu.state_codec')
            ->arg(0, $config['secret'])
            ->arg(1, $config['state_max_age'])
            ->arg(2, $config['state_bind_session']);

        $bundles = $builder->getParameter('kernel.bundles');
        $doctrine = \is_array($bundles) && isset($bundles['DoctrineBundle']) && interface_exists(EntityManagerInterface::class);
        if ($doctrine) {
            $container->import('../config/doctrine.php');
        }

        $transactional = $config['transactional'] ?? $doctrine;
        if ($transactional && !$doctrine) {
            throw new InvalidConfigurationException('The "mamu.transactional" option needs Doctrine ORM: install doctrine/orm and doctrine/doctrine-bundle, or set the option to false.');
        }
        $container->services()->get('mamu.handler_middleware.transactional')
            ->arg(1, $transactional);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return;
        }
        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [\dirname(__DIR__).'/assets/dist' => 'mamujs'],
            ],
        ]);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(PathValueConverter::class)->addTag('mamu.path_converter');
        $container->registerForAutoconfiguration(HandlerMiddleware::class)->addTag('mamu.handler_middleware');
        // Before the autoconfiguration passes, so the component services are autoconfigured.
        $container->addCompilerPass(new ComponentPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 200);
    }

    public function boot(): void
    {
        // Function components cannot be autoloaded: load the compiled code once per boot.
        $manifest = $this->container?->get('mamu.manifest');
        if ($manifest instanceof Manifest && $manifest->exists()) {
            $manifest->load();
        }
    }
}
