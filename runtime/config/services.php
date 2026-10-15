<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Rephp\Command\RenderCommand;
use Rephp\Command\ServeCommand;
use Rephp\Command\WarmupCommand;
use Rephp\Controller\EventController;
use Rephp\Controller\PageController;
use Rephp\EventListener\SourceMapListener;
use Rephp\Manifest;
use Rephp\Rendering\PropBinder;
use Rephp\Rendering\Renderer;
use Rephp\Routing\PageLoader;
use Rephp\StateCodec;
use Rephp\Twig\RephpExtension;
use Rephp\Twig\RephpRuntime;
use Rephp\Twig\TemplateLoader;
use Rephp\Twig\TemplateWarmer;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('rephp.manifest', Manifest::class)
            ->public()
            ->args([param('rephp.build_dir'), param('rephp.source_dir')])
        ->alias(Manifest::class, 'rephp.manifest')

        ->set('rephp.prop_binder', PropBinder::class)

        ->set('rephp.renderer', Renderer::class)
            ->args([
                service('twig'),
                service('rephp.manifest'),
                abstract_arg('class components, set by ComponentPass'),
                service('rephp.prop_binder'),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
        ->alias(Renderer::class, 'rephp.renderer')

        ->set('rephp.state_codec', StateCodec::class)
            ->args([abstract_arg('secret, set by RephpBundle')])
        ->alias(StateCodec::class, 'rephp.state_codec')

        // Twig
        ->set('rephp.twig.extension', RephpExtension::class)
            ->args([service('rephp.manifest')])
            ->tag('twig.extension')
        ->set('rephp.twig.runtime', RephpRuntime::class)
            ->args([service('rephp.renderer')])
            ->tag('twig.runtime')
        ->set('rephp.twig.loader', TemplateLoader::class)
            ->args(['%rephp.build_dir%/templates'])
            ->tag('twig.loader')
        ->set('rephp.twig.template_warmer', TemplateWarmer::class)
            ->args([service('twig'), service('rephp.manifest')])
            ->tag('kernel.cache_warmer')

        // HTTP
        ->set('rephp.route_loader', PageLoader::class)
            ->args([service('rephp.manifest'), param('kernel.environment')])
            ->tag('routing.loader')
        ->set('rephp.controller.page', PageController::class)
            ->public()
            ->args([service('rephp.renderer'), service('rephp.state_codec'), service('twig')])
        ->set('rephp.controller.event', EventController::class)
            ->public()
            ->args([service('rephp.renderer'), service('rephp.state_codec'), service('rephp.manifest')])
        ->set('rephp.listener.source_map', SourceMapListener::class)
            ->args([service('rephp.manifest')])
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128])
            ->tag('kernel.event_listener', ['event' => 'console.error', 'method' => 'onConsoleError', 'priority' => 128])

        // Console
        ->set('rephp.command.warmup', WarmupCommand::class)
            ->args([service('rephp.twig.template_warmer')])
            ->tag('console.command')
        ->set('rephp.command.render', RenderCommand::class)
            ->args([service('http_kernel')])
            ->tag('console.command')
        ->set('rephp.command.serve', ServeCommand::class)
            ->args([service('kernel'), param('kernel.project_dir'), param('kernel.debug')])
            ->tag('console.command')
    ;
};
