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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Mamu\Binding\PropBinder;
use Mamu\Command\RenderCommand;
use Mamu\Command\WarmupCommand;
use Mamu\Controller\EventController;
use Mamu\Controller\PageController;
use Mamu\EventListener\PageAttributesListener;
use Mamu\EventListener\SourceMapListener;
use Mamu\Handler\HandlerFingerprint;
use Mamu\Handler\HandlerInvoker;
use Mamu\Handler\TransactionalMiddleware;
use Mamu\Http\PageRenderer;
use Mamu\Http\TurboStreams;
use Mamu\Manifest\Manifest;
use Mamu\Manifest\SourceMap;
use Mamu\Metadata\ComponentMetadata;
use Mamu\Path\EntityPathConverter;
use Mamu\Path\PathResolver;
use Mamu\Path\ScalarPathConverter;
use Mamu\Rendering\ClassComponentRenderer;
use Mamu\Rendering\ContainerComponentFactory;
use Mamu\Rendering\FunctionComponentRenderer;
use Mamu\Rendering\MarkupResolver;
use Mamu\Rendering\Renderer;
use Mamu\Rendering\TemplateComponentRenderer;
use Mamu\Routing\PageLoader;
use Mamu\State\StateAccessor;
use Mamu\State\StateCodec;
use Mamu\Twig\CacheKeyLoader;
use Mamu\Twig\MamuExtension;
use Mamu\Twig\MamuRuntime;
use Mamu\Twig\TemplateLoader;
use Mamu\Twig\TemplateWarmer;

return static function (ContainerConfigurator $container): void {
    $container->services()
        // Build
        ->set('mamu.manifest', Manifest::class)
            ->public()
            ->args([param('mamu.build_dir'), param('mamu.source_dir')])
        ->alias(Manifest::class, 'mamu.manifest')
        ->set('mamu.source_map', SourceMap::class)
            ->args([service('mamu.manifest')])

        // Props and state
        ->set('mamu.metadata', ComponentMetadata::class)
        ->set('mamu.prop_binder', PropBinder::class)
            ->args([service('mamu.metadata')])
        ->set('mamu.state_accessor', StateAccessor::class)
            ->args([service('mamu.metadata')])
        ->set('mamu.state_codec', StateCodec::class)
            ->args([abstract_arg('secret, set by MamuBundle'), 86400, false])
        ->alias(StateCodec::class, 'mamu.state_codec')

        // Path values: the converters of the #[Path] props
        ->set('mamu.path_resolver', PathResolver::class)
            ->args([service('mamu.metadata'), tagged_iterator('mamu.path_converter')])
        ->set('mamu.path_converter.scalar', ScalarPathConverter::class)
            ->tag('mamu.path_converter', ['priority' => -100])
        ->set('mamu.path_converter.entity', EntityPathConverter::class)
            ->args([service('mamu.entity_finder')->nullOnInvalid()])
            ->tag('mamu.path_converter', ['priority' => -100])

        // Event handlers: the middleware around them
        ->set('mamu.handler_fingerprint', HandlerFingerprint::class)
            ->args([service('mamu.object_identifier')->nullOnInvalid()])
        ->set('mamu.handler_invoker', HandlerInvoker::class)
            ->args([tagged_iterator('mamu.handler_middleware')])
        ->set('mamu.handler_middleware.transactional', TransactionalMiddleware::class)
            ->args([service('mamu.transaction_runner')->nullOnInvalid()])
            ->tag('mamu.handler_middleware', ['priority' => -100])

        // Rendering
        ->set('mamu.component_factory', ContainerComponentFactory::class)
            ->args([abstract_arg('class components, set by ComponentPass')])
        ->set('mamu.component_renderer.class', ClassComponentRenderer::class)
            ->args([service('mamu.component_factory'), service('mamu.prop_binder'), service('mamu.state_accessor')])
            ->tag('mamu.component_renderer')
        ->set('mamu.component_renderer.function', FunctionComponentRenderer::class)
            ->args([service('mamu.prop_binder')])
            ->tag('mamu.component_renderer')
        ->set('mamu.template_renderer', TemplateComponentRenderer::class)
            ->args([service('mamu.prop_binder')])
        ->set('mamu.markup_resolver', MarkupResolver::class)
            ->args([service('twig')])
        ->set('mamu.renderer', Renderer::class)
            ->args([
                service('mamu.manifest'),
                service('mamu.markup_resolver'),
                tagged_iterator('mamu.component_renderer'),
                service('mamu.handler_invoker'),
                service('mamu.handler_fingerprint'),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
        ->alias(Renderer::class, 'mamu.renderer')

        // Twig
        ->set('mamu.twig.extension', MamuExtension::class)
            ->args([service('mamu.manifest')])
            ->tag('twig.extension')
        ->set('mamu.twig.runtime', MamuRuntime::class)
            ->args([service('mamu.renderer'), service('mamu.template_renderer')])
            ->tag('twig.runtime')
        ->set('mamu.twig.loader', TemplateLoader::class)
            ->args(['%mamu.build_dir%/templates'])
            ->tag('twig.loader')
        ->set('mamu.twig.cache_key_loader', CacheKeyLoader::class)
            ->decorate('twig.loader')
            ->args([service('.inner'), service('mamu.manifest')])
        ->set('mamu.twig.template_warmer', TemplateWarmer::class)
            ->args([service('twig'), service('mamu.manifest')])
            ->tag('kernel.cache_warmer')

        // HTTP
        ->set('mamu.route_loader', PageLoader::class)
            ->args([service('mamu.manifest'), param('kernel.environment'), service('mamu.metadata')])
            ->tag('routing.loader')
        ->set('mamu.turbo_streams', TurboStreams::class)
        ->set('mamu.page_renderer', PageRenderer::class)
            ->args([
                service('mamu.renderer'),
                service('mamu.state_codec'),
                service('mamu.metadata'),
                service('mamu.path_resolver'),
                service('mamu.state_accessor'),
            ])
        ->alias(PageRenderer::class, 'mamu.page_renderer')
        ->set('mamu.controller.page', PageController::class)
            ->public()
            ->args([service('mamu.page_renderer'), service('twig'), param('mamu.page_template')])
        ->set('mamu.controller.event', EventController::class)
            ->public()
            ->args([
                service('mamu.renderer'),
                service('mamu.state_codec'),
                service('mamu.controller.page'),
                service('mamu.metadata'),
                service('mamu.path_resolver'),
                service('mamu.state_accessor'),
                service('mamu.turbo_streams'),
            ])
        ->set('mamu.listener.page_attributes', PageAttributesListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onKernelController'])
        ->set('mamu.listener.source_map', SourceMapListener::class)
            ->args([service('mamu.source_map')])
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128])
            ->tag('kernel.event_listener', ['event' => 'console.error', 'method' => 'onConsoleError', 'priority' => 128])

        // Console
        ->set('mamu.command.warmup', WarmupCommand::class)
            ->args([service('mamu.twig.template_warmer')])
            ->tag('console.command')
        ->set('mamu.command.render', RenderCommand::class)
            ->args([service('http_kernel')])
            ->tag('console.command')
    ;
};
