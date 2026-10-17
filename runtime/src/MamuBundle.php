<?php

declare(strict_types=1);

namespace Mamu;

use Doctrine\ORM\EntityManagerInterface;
use Mamu\DependencyInjection\ComponentPass;
use Mamu\Handler\HandlerMiddleware;
use Mamu\Manifest\Manifest;
use Mamu\Path\PathValueConverter;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
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
 *         page_template: '@Mamu/page.html.twig'   # extends the base.html.twig of the app
 *
 *     # config/routes/mamu.yaml
 *     mamu:
 *         resource: .
 *         type: mamu
 *
 * The page template belongs to the application: it gets the page as `page`, and it prints
 * the root element with `{{ mamu_root(page) }}` (see Mamu\Http\PageView). The client (the
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
                ->scalarNode('page_template')
                    ->info('The Twig template of the pages. It gets the page as `page`. The default template extends the base.html.twig of the application. The `_mamu_template` default of a route changes it for one page.')
                    ->defaultValue('@Mamu/page.html.twig')
                    ->cannotBeEmpty()
                ->end()
            ->end();
    }

    /**
     * @param array{source_dir: string, build_dir: string, secret: string, state_max_age: int, state_bind_session: bool, page_template: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('mamu.source_dir', $config['source_dir'])
            ->set('mamu.build_dir', $config['build_dir'])
            ->set('mamu.page_template', $config['page_template']);
        $container->import('../config/services.php');
        $container->services()->get('mamu.state_codec')
            ->arg(0, $config['secret'])
            ->arg(1, $config['state_max_age'])
            ->arg(2, $config['state_bind_session']);

        $bundles = $builder->getParameter('kernel.bundles');
        if (\is_array($bundles) && isset($bundles['DoctrineBundle']) && interface_exists(EntityManagerInterface::class)) {
            $container->import('../config/doctrine.php');
        }
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
