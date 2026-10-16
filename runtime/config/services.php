<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Rephp\Binding\PropBinder;
use Rephp\Command\RenderCommand;
use Rephp\Command\WarmupCommand;
use Rephp\Controller\EventController;
use Rephp\Controller\PageController;
use Rephp\EventListener\SourceMapListener;
use Rephp\Handler\HandlerInvoker;
use Rephp\Handler\TransactionalMiddleware;
use Rephp\Http\TurboStreams;
use Rephp\Manifest\Manifest;
use Rephp\Manifest\SourceMap;
use Rephp\Metadata\ComponentMetadata;
use Rephp\Path\EntityPathConverter;
use Rephp\Path\PathResolver;
use Rephp\Path\ScalarPathConverter;
use Rephp\Rendering\ClassComponentRenderer;
use Rephp\Rendering\ContainerComponentFactory;
use Rephp\Rendering\FunctionComponentRenderer;
use Rephp\Rendering\MarkupResolver;
use Rephp\Rendering\Renderer;
use Rephp\Rendering\TemplateComponentRenderer;
use Rephp\Routing\PageLoader;
use Rephp\State\StateAccessor;
use Rephp\State\StateCodec;
use Rephp\Twig\CacheKeyLoader;
use Rephp\Twig\RephpExtension;
use Rephp\Twig\RephpRuntime;
use Rephp\Twig\TemplateLoader;
use Rephp\Twig\TemplateWarmer;

return static function (ContainerConfigurator $container): void {
    $container->services()
        // Build
        ->set('rephp.manifest', Manifest::class)
            ->public()
            ->args([param('rephp.build_dir'), param('rephp.source_dir')])
        ->alias(Manifest::class, 'rephp.manifest')
        ->set('rephp.source_map', SourceMap::class)
            ->args([service('rephp.manifest')])

        // Props and state
        ->set('rephp.metadata', ComponentMetadata::class)
        ->set('rephp.prop_binder', PropBinder::class)
            ->args([service('rephp.metadata')])
        ->set('rephp.state_accessor', StateAccessor::class)
            ->args([service('rephp.metadata')])
        ->set('rephp.state_codec', StateCodec::class)
            ->args([abstract_arg('secret, set by RephpBundle')])
        ->alias(StateCodec::class, 'rephp.state_codec')

        // Path values: the converters of the #[Path] props
        ->set('rephp.path_resolver', PathResolver::class)
            ->args([service('rephp.metadata'), tagged_iterator('rephp.path_converter')])
        ->set('rephp.path_converter.scalar', ScalarPathConverter::class)
            ->tag('rephp.path_converter', ['priority' => -100])
        ->set('rephp.path_converter.entity', EntityPathConverter::class)
            ->args([service('rephp.entity_finder')->nullOnInvalid()])
            ->tag('rephp.path_converter', ['priority' => -100])

        // Event handlers: the middleware around them
        ->set('rephp.handler_invoker', HandlerInvoker::class)
            ->args([tagged_iterator('rephp.handler_middleware')])
        ->set('rephp.handler_middleware.transactional', TransactionalMiddleware::class)
            ->args([service('rephp.transaction_runner')->nullOnInvalid()])
            ->tag('rephp.handler_middleware', ['priority' => -100])

        // Rendering
        ->set('rephp.component_factory', ContainerComponentFactory::class)
            ->args([abstract_arg('class components, set by ComponentPass')])
        ->set('rephp.component_renderer.class', ClassComponentRenderer::class)
            ->args([service('rephp.component_factory'), service('rephp.prop_binder'), service('rephp.state_accessor')])
            ->tag('rephp.component_renderer')
        ->set('rephp.component_renderer.function', FunctionComponentRenderer::class)
            ->args([service('rephp.prop_binder')])
            ->tag('rephp.component_renderer')
        ->set('rephp.template_renderer', TemplateComponentRenderer::class)
            ->args([service('rephp.prop_binder')])
        ->set('rephp.markup_resolver', MarkupResolver::class)
            ->args([service('twig')])
        ->set('rephp.renderer', Renderer::class)
            ->args([
                service('rephp.manifest'),
                service('rephp.markup_resolver'),
                tagged_iterator('rephp.component_renderer'),
                service('rephp.handler_invoker'),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
        ->alias(Renderer::class, 'rephp.renderer')

        // Twig
        ->set('rephp.twig.extension', RephpExtension::class)
            ->args([service('rephp.manifest')])
            ->tag('twig.extension')
        ->set('rephp.twig.runtime', RephpRuntime::class)
            ->args([service('rephp.renderer'), service('rephp.template_renderer')])
            ->tag('twig.runtime')
        ->set('rephp.twig.loader', TemplateLoader::class)
            ->args(['%rephp.build_dir%/templates'])
            ->tag('twig.loader')
        ->set('rephp.twig.cache_key_loader', CacheKeyLoader::class)
            ->decorate('twig.loader')
            ->args([service('.inner'), service('rephp.manifest')])
        ->set('rephp.twig.template_warmer', TemplateWarmer::class)
            ->args([service('twig'), service('rephp.manifest')])
            ->tag('kernel.cache_warmer')

        // HTTP
        ->set('rephp.route_loader', PageLoader::class)
            ->args([service('rephp.manifest'), param('kernel.environment'), service('rephp.metadata')])
            ->tag('routing.loader')
        ->set('rephp.turbo_streams', TurboStreams::class)
        ->set('rephp.controller.page', PageController::class)
            ->public()
            ->args([
                service('rephp.renderer'),
                service('rephp.state_codec'),
                service('twig'),
                service('rephp.metadata'),
                service('rephp.path_resolver'),
                service('rephp.state_accessor'),
            ])
        ->set('rephp.controller.event', EventController::class)
            ->public()
            ->args([
                service('rephp.renderer'),
                service('rephp.state_codec'),
                service('rephp.manifest'),
                service('rephp.path_resolver'),
                service('rephp.state_accessor'),
                service('rephp.turbo_streams'),
            ])
        ->set('rephp.listener.source_map', SourceMapListener::class)
            ->args([service('rephp.source_map')])
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128])
            ->tag('kernel.event_listener', ['event' => 'console.error', 'method' => 'onConsoleError', 'priority' => 128])

        // Console
        ->set('rephp.command.warmup', WarmupCommand::class)
            ->args([service('rephp.twig.template_warmer')])
            ->tag('console.command')
        ->set('rephp.command.render', RenderCommand::class)
            ->args([service('http_kernel')])
            ->tag('console.command')
    ;
};
