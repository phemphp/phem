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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Phem\Binding\PropBinder;
use Phem\BrowserEventDispatcher;
use Phem\Command\RenderCommand;
use Phem\Command\WarmupCommand;
use Phem\Controller\EventController;
use Phem\Controller\PageController;
use Phem\EventListener\PageAttributesListener;
use Phem\EventListener\SourceMapListener;
use Phem\Handler\HandlerFingerprint;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\TransactionalMiddleware;
use Phem\Http\EventBody;
use Phem\Http\PageRenderer;
use Phem\Http\TurboStreams;
use Phem\Manifest\Manifest;
use Phem\Manifest\SourceMap;
use Phem\Metadata\ComponentMetadata;
use Phem\Path\EntityPathConverter;
use Phem\Path\PathResolver;
use Phem\Path\ScalarPathConverter;
use Phem\Rendering\ClassComponentRenderer;
use Phem\Rendering\ContainerComponentFactory;
use Phem\Rendering\EventRenderer;
use Phem\Rendering\FunctionComponentRenderer;
use Phem\Rendering\InputsComparator;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\Rendering\TemplateComponentRenderer;
use Phem\Routing\PageLoader;
use Phem\State\BoundValues;
use Phem\State\StateAccessor;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use Phem\State\UrlQuery;
use Phem\Twig\CacheKeyLoader;
use Phem\Twig\PhemExtension;
use Phem\Twig\PhemRuntime;
use Phem\Twig\TemplateLoader;
use Phem\Twig\TemplateWarmer;

return static function (ContainerConfigurator $container): void {
    $container->services()
        // Build
        ->set('phem.manifest', Manifest::class)
            ->public()
            ->args([param('phem.build_dir'), param('phem.source_dir')])
        ->alias(Manifest::class, 'phem.manifest')
        ->set('phem.source_map', SourceMap::class)
            ->args([service('phem.manifest')])

        // Props and state
        ->set('phem.metadata', ComponentMetadata::class)
        ->set('phem.prop_binder', PropBinder::class)
            ->args([service('phem.metadata')])
        ->set('phem.state_accessor', StateAccessor::class)
            ->args([service('phem.metadata')])
        ->set('phem.bound_values', BoundValues::class)
            ->args([service('phem.metadata')])
        ->set('phem.url_query', UrlQuery::class)
            ->args([service('phem.metadata')])
        ->set('phem.state_codec', StateCodec::class)
            ->args([abstract_arg('secret, set by PhemBundle'), 86400, false])
        ->alias(StateCodec::class, 'phem.state_codec')

        // Path values: the converters of the #[Path] props
        ->set('phem.path_resolver', PathResolver::class)
            ->args([service('phem.metadata'), tagged_iterator('phem.path_converter')])
        ->set('phem.path_converter.scalar', ScalarPathConverter::class)
            ->tag('phem.path_converter', ['priority' => -100])
        ->set('phem.path_converter.entity', EntityPathConverter::class)
            ->args([service('phem.entity_finder')->nullOnInvalid()])
            ->tag('phem.path_converter', ['priority' => -100])

        // Event handlers: the middleware around them
        ->set('phem.handler_fingerprint', HandlerFingerprint::class)
            ->args([service('phem.object_identifier')->nullOnInvalid()])
        ->set('phem.handler_invoker', HandlerInvoker::class)
            ->args([tagged_iterator('phem.handler_middleware')])
        ->set('phem.handler_middleware.transactional', TransactionalMiddleware::class)
            ->args([service('phem.transaction_runner')->nullOnInvalid()])
            ->tag('phem.handler_middleware', ['priority' => -100])
        ->set('phem.browser_event_dispatcher', BrowserEventDispatcher::class)
            ->tag('kernel.reset', ['method' => 'reset'])
        ->alias(BrowserEventDispatcher::class, 'phem.browser_event_dispatcher')

        // Rendering
        ->set('phem.component_factory', ContainerComponentFactory::class)
            ->args([abstract_arg('class components, set by ComponentPass')])
        ->set('phem.component_renderer.class', ClassComponentRenderer::class)
            ->args([service('phem.component_factory'), service('phem.prop_binder'), service('phem.state_accessor'), service('phem.url_query'), service('phem.metadata')])
            ->tag('phem.component_renderer')
        ->set('phem.component_renderer.function', FunctionComponentRenderer::class)
            ->args([service('phem.prop_binder')])
            ->tag('phem.component_renderer')
        ->set('phem.template_renderer', TemplateComponentRenderer::class)
            ->args([service('phem.prop_binder')])
        ->set('phem.markup_resolver', MarkupResolver::class)
            ->args([service('twig')])
        ->set('phem.renderer', Renderer::class)
            ->args([
                service('phem.manifest'),
                service('phem.markup_resolver'),
                tagged_iterator('phem.component_renderer'),
                service('phem.handler_invoker'),
                service('phem.handler_fingerprint'),
                service('phem.metadata'),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
        ->alias(Renderer::class, 'phem.renderer')

        // Twig
        ->set('phem.twig.extension', PhemExtension::class)
            ->args([service('phem.manifest')])
            ->tag('twig.extension')
        ->set('phem.twig.runtime', PhemRuntime::class)
            ->args([service('phem.renderer'), service('phem.template_renderer')])
            ->tag('twig.runtime')
        ->set('phem.twig.loader', TemplateLoader::class)
            ->args(['%phem.build_dir%/templates'])
            ->tag('twig.loader')
        ->set('phem.twig.cache_key_loader', CacheKeyLoader::class)
            ->decorate('twig.loader')
            ->args([service('.inner'), service('phem.manifest')])
        ->set('phem.twig.template_warmer', TemplateWarmer::class)
            ->args([service('twig'), service('phem.manifest')])
            ->tag('kernel.cache_warmer')

        // HTTP
        ->set('phem.route_loader', PageLoader::class)
            ->args([service('phem.manifest'), param('kernel.environment'), service('phem.metadata')])
            ->tag('routing.loader')
        ->set('phem.turbo_streams', TurboStreams::class)
        ->set('phem.page_renderer', PageRenderer::class)
            ->args([
                service('phem.renderer'),
                service('phem.state_codec'),
                service('phem.metadata'),
                service('phem.path_resolver'),
                service('phem.state_accessor'),
                service('phem.url_query'),
            ])
        ->alias(PageRenderer::class, 'phem.page_renderer')
        ->set('phem.controller.page', PageController::class)
            ->public()
            ->args([service('phem.page_renderer')])
        ->set('phem.inputs_comparator', InputsComparator::class)
            ->args([service('phem.handler_fingerprint')])
        ->set('phem.event_renderer', EventRenderer::class)
            ->args([
                service('phem.renderer'),
                service('phem.bound_values'),
                service('phem.state_accessor'),
                service('phem.inputs_comparator'),
                service('phem.metadata'),
                null,
            ])
        ->alias(EventRenderer::class, 'phem.event_renderer')
        ->set('phem.state_cache', StateCache::class)
            ->args([service(StateCache::POOL)])
        ->set('phem.event_body', EventBody::class)
            ->args([1048576])
        ->set('phem.controller.event', EventController::class)
            ->public()
            ->args([
                service('phem.event_renderer'),
                service('phem.state_codec'),
                service('phem.controller.page'),
                service('phem.metadata'),
                service('phem.path_resolver'),
                service('phem.state_accessor'),
                service('phem.turbo_streams'),
                service('phem.browser_event_dispatcher'),
                service('phem.event_body'),
                service('phem.url_query'),
                8388608,
            ])
        ->set('phem.listener.page_attributes', PageAttributesListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onKernelController'])
        ->set('phem.listener.source_map', SourceMapListener::class)
            ->args([service('phem.source_map')])
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128])
            ->tag('kernel.event_listener', ['event' => 'console.error', 'method' => 'onConsoleError', 'priority' => 128])

        // Console
        ->set('phem.command.warmup', WarmupCommand::class)
            ->args([service('phem.twig.template_warmer')])
            ->tag('console.command')
        ->set('phem.command.render', RenderCommand::class)
            ->args([service('http_kernel')])
            ->tag('console.command')
    ;
};
