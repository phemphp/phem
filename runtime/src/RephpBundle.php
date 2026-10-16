<?php

declare(strict_types=1);

namespace Rephp;

use Doctrine\ORM\EntityManagerInterface;
use Rephp\DependencyInjection\ComponentPass;
use Rephp\Handler\HandlerMiddleware;
use Rephp\Path\PathValueConverter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integrates `.rhp` components in a Symfony application.
 *
 *     # config/packages/rephp.yaml (all options are optional)
 *     rephp:
 *         source_dir: '%kernel.project_dir%/src'
 *         build_dir: '%kernel.project_dir%/var/cache/rhp'
 *         secret: '%kernel.secret%'
 *         state_max_age: 86400       # seconds; 0 for no limit
 *         state_bind_session: false
 *
 *     # config/routes/rephp.yaml
 *     rephp:
 *         resource: .
 *         type: rephp
 *
 * Extension points (autoconfigured services):
 *
 * - a Rephp\Path\PathValueConverter converts the route values of other #[Path] prop types;
 * - a Rephp\Handler\HandlerMiddleware runs code around the event handlers;
 * - the `rephp.object_identifier` service (a Rephp\Handler\ObjectIdentifier) identifies the
 *   objects that the handlers capture (Doctrine entities, when DoctrineBundle is installed).
 */
final class RephpBundle extends AbstractBundle
{
    protected string $extensionAlias = 'rephp';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('source_dir')
                    ->info('The .rhp sources (the directory that you pass to `rhpc build`)')
                    ->defaultValue('%kernel.project_dir%/src')
                ->end()
                ->scalarNode('build_dir')
                    ->info('The rhpc output (`rhpc build --out`)')
                    ->defaultValue('%kernel.project_dir%/var/cache/rhp')
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
            ->end();
    }

    /** 
     * @param array{source_dir: string, build_dir: string, secret: string, state_max_age: int, state_bind_session: bool} $config 
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('rephp.source_dir', $config['source_dir'])
            ->set('rephp.build_dir', $config['build_dir']);
        $container->import('../config/services.php');
        $container->services()->get('rephp.state_codec')
            ->arg(0, $config['secret'])
            ->arg(1, $config['state_max_age'])
            ->arg(2, $config['state_bind_session']);

        if (isset($builder->getParameter('kernel.bundles')['DoctrineBundle']) && interface_exists(EntityManagerInterface::class)) {
            $container->import('../config/doctrine.php');
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(PathValueConverter::class)->addTag('rephp.path_converter');
        $container->registerForAutoconfiguration(HandlerMiddleware::class)->addTag('rephp.handler_middleware');
        // Before the autoconfiguration passes, so the component services are autoconfigured.
        $container->addCompilerPass(new ComponentPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 200);
    }

    public function boot(): void
    {
        // Function components cannot be autoloaded: load the compiled code once per boot.
        $manifest = $this->container->get('rephp.manifest');
        if ($manifest->exists()) {
            $manifest->load();
        }
    }
}
