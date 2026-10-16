<?php

declare(strict_types=1);

namespace Rephp;

use Doctrine\ORM\EntityManagerInterface;
use Rephp\DependencyInjection\ComponentPass;
use Rephp\Doctrine\DoctrineEntityFinder;
use Rephp\Doctrine\DoctrineTransactionRunner;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Integrates `.rhp` components in a Symfony application.
 *
 *     # config/packages/rephp.yaml (all options are optional)
 *     rephp:
 *         source_dir: '%kernel.project_dir%/src'
 *         build_dir: '%kernel.project_dir%/var/cache/rhp'
 *         secret: '%kernel.secret%'
 *
 *     # config/routes/rephp.yaml
 *     rephp:
 *         resource: .
 *         type: rephp
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
            ->end();
    }

    /** 
     * @param array{source_dir: string, build_dir: string, secret: string} $config 
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('rephp.source_dir', $config['source_dir'])
            ->set('rephp.build_dir', $config['build_dir']);
        $container->import('../config/services.php');
        $container->services()->get('rephp.state_codec')->arg(0, $config['secret']);

        // The handlers of #[Transactional] components run in a Doctrine ORM transaction, and
        // the #[Path] props with an entity type get their entity.
        if (isset($builder->getParameter('kernel.bundles')['DoctrineBundle']) && interface_exists(EntityManagerInterface::class)) {
            $container->services()
                ->set('rephp.transaction_runner', DoctrineTransactionRunner::class)
                    ->args([service('doctrine')])
                ->get('rephp.renderer')
                    ->arg(4, service('rephp.transaction_runner'))
                ->set('rephp.entity_finder', DoctrineEntityFinder::class)
                    ->args([service('doctrine')])
                ->get('rephp.prop_binder')
                    ->arg(0, service('rephp.entity_finder'));
        }
    }

    public function build(ContainerBuilder $container): void
    {
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
