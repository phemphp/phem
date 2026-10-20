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

namespace Phem;

use Doctrine\ORM\EntityManagerInterface;
use Phem\DependencyInjection\ComponentPass;
use Phem\Handler\HandlerMiddleware;
use Phem\Handler\RateLimitMiddleware;
use Phem\Manifest\Manifest;
use Phem\Path\PathValueConverter;
use Phem\Profiler\PhemDataCollector;
use Phem\Profiler\Profile;
use Phem\Profiler\ProfiledTransactionRunner;
use Phem\Profiler\ProfilerMiddleware;
use Phem\Rendering\SkippedRenderCheck;
use Phem\State\StateCache;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

/**
 * Integrates `.phpm` components in a Symfony application.
 *
 *     # config/packages/phem.yaml (all options are optional)
 *     phem:
 *         source_dir: '%kernel.project_dir%/src'
 *         build_dir: '%kernel.project_dir%/var/cache/phem'
 *         secret: '%kernel.secret%'
 *         state_max_age: 86400       # seconds; 0 for no limit
 *         state_bind_session: false
 *         state_cache: true          # keep the signed states in the cache.phem.state pool
 *         max_event_size: 1048576    # bytes; 0 for no limit
 *         max_upload_size: 8388608   # bytes; 0 for no limit
 *         transactional: ~           # true when Doctrine ORM is installed and enabled
 *
 *     # config/routes/phem.yaml
 *     phem:
 *         resource: .
 *         type: phem
 *
 * A page is a class component with `#[Page]` (or `#[Route]`). It renders the document of the
 * application (`<html>`, `<head>`, `<body>`): a component that renders the page content in a
 * `<Root>` (see Phem\Root). The client (the
 * `phem` Stimulus controller and its CSS) is a Symfony UX package in assets/: with AssetMapper,
 * its files are in the `phemjs` namespace, and StimulusBundle loads the controller
 * from the `assets/controllers.json` of the application.
 *
 * Extension points (autoconfigured services):
 *
 * - a Phem\Path\PathValueConverter converts the route values of other #[Path] prop types;
 * - a Phem\Handler\HandlerMiddleware runs code around the event handlers;
 * - the `phem.object_identifier` service (a Phem\Handler\ObjectIdentifier) identifies the
 *   objects that the handlers capture (Doctrine entities, when DoctrineBundle is installed).
 */
final class PhemBundle extends AbstractBundle
{
    protected string $extensionAlias = 'phem';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('source_dir')
                    ->info('The .phpm sources (the directory that you pass to `phemc build`)')
                    ->defaultValue('%kernel.project_dir%/src')
                ->end()
                ->scalarNode('build_dir')
                    ->info('The phemc output (`phemc build --out`)')
                    ->defaultValue('%kernel.project_dir%/var/cache/phem')
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
                ->booleanNode('state_cache')
                    ->info('Keep each signed state in the cache.phem.state pool (framework.cache.pools), so an event can send only its signature. The browser sends the full state when the pool does not have it.')
                    ->defaultTrue()
                ->end()
                ->integerNode('max_event_size')
                    ->info('The maximum size of an event body in bytes, with the state of the page (0 for no limit). A larger event gets a 413 response.')
                    ->defaultValue(1048576)
                    ->min(0)
                ->end()
                ->integerNode('max_upload_size')
                    ->info('The maximum size of the files of an event in bytes, together (0 for no limit). Larger files get a 413 response. The post_max_size option of PHP also applies, and a file over upload_max_filesize gets to the handler with an upload error.')
                    ->defaultValue(8388608)
                    ->min(0)
                ->end()
                ->booleanNode('transactional')
                    ->info('Run the event handlers of all components in a Doctrine ORM transaction. Set it to false to use #[Transactional] on each component instead. Default: true when Doctrine ORM is installed and enabled.')
                    ->defaultNull()
                ->end()
            ->end();
    }

    /**
     * @param array{source_dir: string, build_dir: string, secret: string, state_max_age: int, state_bind_session: bool, state_cache: bool, max_event_size: int, max_upload_size: int, transactional: bool|null} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('phem.source_dir', $config['source_dir'])
            ->set('phem.build_dir', $config['build_dir']);
        $container->import('../config/services.php');
        $container->services()->get('phem.state_codec')
            ->arg(0, $config['secret'])
            ->arg(1, $config['state_max_age'])
            ->arg(2, $config['state_bind_session']);
        if (!$config['state_cache']) {
            $container->services()->get('phem.state_cache')->arg(0, null);
        }
        $container->services()->get('phem.event_body')
            ->arg(0, $config['max_event_size']);
        $container->services()->get('phem.controller.event')
            ->arg(10, $config['max_upload_size']);
        if ($builder->getParameter('kernel.debug')) {
            // As React StrictMode: render the full page after each event, and warn when a kept
            // component changed (a render() that is not pure).
            $container->services()->set('phem.skipped_render_check', SkippedRenderCheck::class)
                ->args([service('phem.renderer'), service('logger')->nullOnInvalid()]);
            $container->services()->get('phem.event_renderer')
                ->arg(5, service('phem.skipped_render_check'));

            // The Phem panel of the Symfony profiler (see PhemDataCollector). The data collector
            // is used only when the profiler is enabled (framework.profiler).
            $container->services()
                ->set('phem.profile', Profile::class)
                    ->args([service('debug.stopwatch')->nullOnInvalid()])
                    ->tag('kernel.reset', ['method' => 'reset'])
                ->set('phem.handler_middleware.profiler', ProfilerMiddleware::class)
                    ->args([service('phem.profile')])
                    // Outside the transaction, inside the rate limit.
                    ->tag('phem.handler_middleware', ['priority' => 50])
                ->set('phem.data_collector', PhemDataCollector::class)
                    ->args([service('phem.profile'), service('phem.state_accessor')])
                    ->tag('data_collector', ['id' => 'phem', 'template' => '@Phem/Collector/phem.html.twig', 'priority' => 260])
                ->get('phem.renderer')
                    ->arg(6, service('phem.profile'));
        }

        $bundles = $builder->getParameter('kernel.bundles');
        $doctrine = \is_array($bundles) && isset($bundles['DoctrineBundle']) && interface_exists(EntityManagerInterface::class);
        if ($doctrine) {
            $container->import('../config/doctrine.php');
            if ($builder->getParameter('kernel.debug')) {
                $container->services()->set('phem.transaction_runner.profiler', ProfiledTransactionRunner::class)
                    ->decorate('phem.transaction_runner')
                    ->args([service('.inner'), service('phem.profile')]);
            }
        }

        $transactional = $config['transactional'] ?? $doctrine;
        if ($transactional && !$doctrine) {
            throw new InvalidConfigurationException('The "phem.transactional" option needs Doctrine ORM: install doctrine/orm and doctrine/doctrine-bundle, or set the option to false.');
        }
        $container->services()->get('phem.handler_middleware.transactional')
            ->arg(1, $transactional);

        if (class_exists(RateLimit::class) && interface_exists(RateLimiterFactoryInterface::class)) {
            // The #[RateLimit] attribute of Symfony 8.1 on a handler method (the limiters of
            // framework.rate_limiter). Before the transaction, so a limited event does not open one.
            $container->services()->set('phem.handler_middleware.rate_limit', RateLimitMiddleware::class)
                ->args([tagged_locator('rate_limiter', 'name'), service('controller.expression_language')->nullOnInvalid()])
                ->tag('phem.handler_middleware', ['priority' => 100]);
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The pool of StateCache. The application can change its adapter (Redis or APCu in
        // production) and its lifetime in framework.cache.pools.
        $builder->prependExtensionConfig('framework', [
            'cache' => [
                'pools' => [StateCache::POOL => ['adapter' => 'cache.app', 'default_lifetime' => 3600]],
            ],
        ]);
        if (interface_exists(AssetMapperInterface::class)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [\dirname(__DIR__).'/assets/dist' => 'phemjs'],
                ],
            ]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(PathValueConverter::class)->addTag('phem.path_converter');
        $container->registerForAutoconfiguration(HandlerMiddleware::class)->addTag('phem.handler_middleware');
        // Before the autoconfiguration passes, so the component services are autoconfigured.
        $container->addCompilerPass(new ComponentPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 200);
    }

    public function boot(): void
    {
        // Function components cannot be autoloaded: load the compiled code once per boot.
        $manifest = $this->container?->get('phem.manifest');
        if ($manifest instanceof Manifest && $manifest->exists()) {
            $manifest->load();
        }
    }
}
