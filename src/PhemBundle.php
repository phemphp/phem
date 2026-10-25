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
use Phem\DependencyInjection\TestAsyncPass;
use Phem\DependencyInjection\TestPublisherPass;
use Phem\Handler\HandlerMiddleware;
use Phem\Handler\RateLimitMiddleware;
use Phem\Manifest\Manifest;
use Phem\Path\PathValueConverter;
use Phem\Profiler\PhemDataCollector;
use Phem\Profiler\Profile;
use Phem\Profiler\ProfiledStreamPublisher;
use Phem\Profiler\ProfiledTransactionRunner;
use Phem\Profiler\ProfilerMiddleware;
use Phem\Rendering\SkippedRenderCheck;
use Phem\State\StateCache;
use Phem\Stream\HubPublisher;
use Phem\Stream\SapiPublisher;
use Phem\Stream\SubscriberToken;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
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
 *         state_cache_min_size: 4096 # bytes; a smaller state goes with each event, not in the pool
 *         max_event_size: 1048576    # bytes; 0 for no limit
 *         max_upload_size: 8388608   # bytes; 0 for no limit
 *         mercure:
 *             subscriber_key: ~      # private updates, with an access token for each page
 *             publisher_key: ~       # publish from a worker, with the HTTP API of the hub
 *             publish_url: ~         # the URL of the hub for the server
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
                ->integerNode('state_cache_min_size')
                    ->info('The bytes of the smallest state that the cache.phem.state pool keeps. An event sends a smaller state with its signature: no request more on a miss, and no cache write. 0 keeps all the states.')
                    ->defaultValue(4096)
                    ->min(0)
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
                ->arrayNode('mercure')
                    ->info('The Mercure hub of FrankenPHP, for the pages with #[Broadcast] (see Phem\\Stream\\Streams)')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('hub_url')
                            ->info('The URL of the hub, as the browser connects to it. It is the audience of the access token: behind a proxy that ends TLS, configure the trusted proxies, or give an absolute URL.')
                            ->defaultValue('/.well-known/mercure')
                        ->end()
                        ->scalarNode('subscriber_key')
                            ->info('The subscriber key of the hub (`issuer … { subscriber { jwt <key> } }` in the Caddyfile). With a key, the updates are private and each page gets an access token for its topics. With null, the updates are public: the hub needs the `anonymous` directive.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('issuer')
                            ->info('The issuer of the hub configuration (`issuer phem { … }`)')
                            ->defaultValue('phem')
                        ->end()
                        ->scalarNode('cookie_name')
                            ->info('The cookie of the access token (`cookie_name` of the hub)')
                            ->defaultValue('__Secure-mercure_access_token')
                        ->end()
                        ->scalarNode('publisher_key')
                            ->info('The publisher key of the hub (`issuer … { publisher { jwt <key> } }` in the Caddyfile). With a key, a process with no FrankenPHP request (a Messenger worker of an #[Async] handler, a command) publishes with the HTTP API of the hub at publish_url. With null, only a FrankenPHP request publishes (mercure_publish()).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('publish_url')
                            ->info('The URL of the hub for the server, with publisher_key (`http://localhost:8080/.well-known/mercure`)')
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $mercure): bool => null !== $mercure['publisher_key'] && null === $mercure['publish_url'])
                        ->thenInvalid('The phem.mercure.publisher_key option needs the publish_url option: the URL of the hub for the server.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $mercure): bool => null !== $mercure['publisher_key'] && !class_exists(HttpClient::class))
                        ->thenInvalid('The phem.mercure.publisher_key option needs Symfony HttpClient: run "composer require symfony/http-client".')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{source_dir: string, build_dir: string, secret: string, state_max_age: int, state_bind_session: bool, state_cache: bool, state_cache_min_size: int, max_event_size: int, max_upload_size: int, mercure: array{hub_url: string, subscriber_key: string|null, issuer: string, cookie_name: string, publisher_key: string|null, publish_url: string|null}} $config
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
        $container->services()->get('phem.state_cache')
            ->arg(1, $config['state_cache_min_size']);
        if (!$config['state_cache']) {
            $container->services()->get('phem.state_cache')->arg(0, null);
        }

        $container->services()->get('phem.event_body')
            ->arg(0, $config['max_event_size']);
        $container->services()->get('phem.controller.event')
            ->arg(10, $config['max_upload_size']);
        $container->services()->get('phem.topics')
            ->arg(1, $config['secret']);
        $container->services()->get('phem.broadcast_keys')
            ->arg(0, $config['secret']);

        $mercure = $config['mercure'];
        if (null !== $mercure['subscriber_key']) {
            // A token lives as long as a state: an older page reloads on its next event.
            $container->services()->set('phem.subscriber_token', SubscriberToken::class)
                ->args([$mercure['subscriber_key'], $mercure['issuer'], $config['state_max_age'] ?: 86400]);
        }

        $container->services()->get('phem.subscription')
            ->arg(1, null !== $mercure['subscriber_key'] ? service('phem.subscriber_token') : null)
            ->arg(2, $mercure['hub_url'])
            ->arg(3, $mercure['cookie_name']);
        $container->services()->get('phem.streams')
            ->arg(3, null !== $mercure['subscriber_key']);

        if (null !== $mercure['publisher_key']) {
            // mercure_publish() in a request, the HTTP API of the hub in a worker.
            $container->services()
                ->set('phem.stream_publisher.hub', HubPublisher::class)
                    ->args([service('http_client'), $mercure['publish_url'], $mercure['publisher_key'], $mercure['issuer'], service('logger')->nullOnInvalid()])
                ->set('phem.stream_publisher', SapiPublisher::class)
                    ->args([service('phem.stream_publisher.frankenphp'), service('phem.stream_publisher.hub')]);
        }

        $container->services()->get('phem.async.dispatcher')->arg(1, $config['secret']);
        if (interface_exists(MessageBusInterface::class)) {
            // The #[Async] handlers: their work runs in a Messenger worker.
            $container->import('../config/messenger.php');
            $container->services()->get('phem.async.handler')->arg(4, $config['secret']);
        }

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
            $container->services()->set('phem.stream_publisher.profiler', ProfiledStreamPublisher::class)
                ->decorate('phem.stream_publisher')
                ->args([service('.inner'), service('phem.profile'), service('phem.topics')]);
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

        if (class_exists(RateLimit::class) && interface_exists(RateLimiterFactoryInterface::class)) {
            // The #[RateLimit] attribute of Symfony 8.1 on a handler method (the limiters of
            // framework.rate_limiter). Before the transaction, so a limited event does not open one.
            $container->services()->set('phem.handler_middleware.rate_limit', RateLimitMiddleware::class)
                ->args([tagged_locator('rate_limiter', 'name'), service('controller.expression_language')->nullOnInvalid(), service('request_stack')])
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
        $container->addCompilerPass(new TestPublisherPass());
        $container->addCompilerPass(new TestAsyncPass());
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
