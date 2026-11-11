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

use Phem\Announcements;
use Phem\Argument\CallableArguments;
use Phem\Argument\CallValueResolver;
use Phem\Argument\EventValueResolver;
use Phem\Argument\MapFormValueResolver;
use Phem\Argument\PathValueResolver;
use Phem\Argument\ServiceValueResolver;
use Phem\Async\AsyncDispatcher;
use Phem\Async\AsyncMiddleware;
use Phem\Async\AsyncUser;
use Phem\Binding\PropBinder;
use Phem\BrowserEventDispatcher;
use Phem\Command\RenderCommand;
use Phem\Command\WarmupCommand;
use Phem\Controller\EventController;
use Phem\Controller\PageController;
use Phem\Dom\ClassAttribute;
use Phem\Dom\ClassMerger;
use Phem\Dom\UniqueClassMerger;
use Phem\EventListener\PageAttributesListener;
use Phem\EventListener\PageStreamListener;
use Phem\EventListener\SourceMapListener;
use Phem\EventListener\StreamListener;
use Phem\Form\FormMapper;
use Phem\Handler\BroadcastMiddleware;
use Phem\Handler\HandlerFingerprint;
use Phem\Handler\HandlerInvoker;
use Phem\Handler\MapFormMiddleware;
use Phem\Handler\RedirectMiddleware;
use Phem\Handler\StreamsMiddleware;
use Phem\Handler\TransactionalMiddleware;
use Phem\Handler\ValidationMiddleware;
use Phem\Http\ErrorPageRenderer;
use Phem\Http\ErrorPages;
use Phem\Http\ErrorPagesWarmer;
use Phem\Http\EventBody;
use Phem\Http\FeedStreams;
use Phem\Http\PageRenderer;
use Phem\Http\PageStream;
use Phem\Http\StatelessPageRenderer;
use Phem\Http\TurboStreams;
use Phem\Manifest\Manifest;
use Phem\Manifest\PreloadWarmer;
use Phem\Manifest\SourceMap;
use Phem\Metadata\ComponentMetadata;
use Phem\Metadata\ContextMetadata;
use Phem\Path\EntityPathConverter;
use Phem\Path\PathResolver;
use Phem\Path\ScalarPathConverter;
use Phem\Rendering\ClassComponentRenderer;
use Phem\Rendering\ContainerComponentFactory;
use Phem\Rendering\ContextResolver;
use Phem\Rendering\ErrorBoundaryRenderer;
use Phem\Rendering\EventRenderer;
use Phem\Rendering\FeedBinder;
use Phem\Rendering\FeedIdentity;
use Phem\Rendering\FeedItemToken;
use Phem\Rendering\FieldErrors;
use Phem\Rendering\FunctionComponentRenderer;
use Phem\Rendering\FunctionPageRenderer;
use Phem\Rendering\InputsComparator;
use Phem\Rendering\MarkupResolver;
use Phem\Rendering\Renderer;
use Phem\Rendering\RootElement;
use Phem\Rendering\SuspenseRenderer;
use Phem\Rendering\SuspenseStream;
use Phem\Rendering\TemplateComponentRenderer;
use Phem\Routing\ControllersLoader;
use Phem\Routing\PageLoader;
use Phem\Routing\PageRouteValues;
use Phem\Routing\PageUrlGenerator;
use Phem\Routing\PageUrlKeys;
use Phem\Routing\PageUrlValues;
use Phem\State\BoundValues;
use Phem\State\StateAccessor;
use Phem\State\StateCache;
use Phem\State\StateCodec;
use Phem\State\StateCopies;
use Phem\State\StateObjects;
use Phem\State\StateSizeLimit;
use Phem\State\StateValidator;
use Phem\State\Type\BackedEnumType;
use Phem\State\Type\ObjectType;
use Phem\State\Type\StateValueType;
use Phem\State\UrlQuery;
use Phem\Stream\BroadcastKeys;
use Phem\Stream\FrankenPhpPublisher;
use Phem\Stream\PathTopicValues;
use Phem\Stream\SenderToken;
use Phem\Stream\Streams;
use Phem\Stream\Subscription;
use Phem\Stream\Topics;
use Phem\Toasts;
use Phem\Twig\CacheKeyLoader;
use Phem\Twig\EnvironmentConfigurator;
use Phem\Twig\PhemExtension;
use Phem\Twig\PhemRuntime;
use Phem\Twig\TemplateLoader;
use Phem\Twig\TemplateWarmer;
use Phem\Upload\ChunkedUploadMode;
use Phem\Upload\ChunkStore;
use Phem\Upload\DirectUploadMode;
use Phem\Upload\UploadController;
use Phem\Upload\UploadResolver;
use Phem\Upload\UploadToken;
use Symfony\Component\DependencyInjection\ContainerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * Build: the manifest of the compiled .phem files
     */

    $services->set('phem.manifest', Manifest::class)
        ->public()
        ->args([param('phem.build_dir')]);
    $services->alias(Manifest::class, 'phem.manifest');

    $services->set('phem.source_map', SourceMap::class)
        ->args([service('phem.manifest'), param('kernel.project_dir')]);

    $services->set('phem.preload_warmer', PreloadWarmer::class)
        ->args([service('phem.manifest')])
        ->tag('kernel.cache_warmer');

    /*
     * Props and state
     */

    $services->set('phem.metadata', ComponentMetadata::class);

    $services->set('phem.prop_binder', PropBinder::class)
        ->args([service('phem.metadata')]);

    $services->set('phem.context_metadata', ContextMetadata::class);

    $services->set('phem.context_resolver', ContextResolver::class)
        ->args([service('phem.context_metadata')]);

    $services->set('phem.state_objects', StateObjects::class)
        ->args([
            service('serializer')->nullOnInvalid(),
            service('phem.object_identifier')->nullOnInvalid(),
        ]);

    // The types of the #[State] properties (see StateType): the first that supports a type keeps its values.
    $services->set('phem.state_type.state_value', StateValueType::class)
        ->tag('phem.state_type', ['priority' => 20]);

    $services->set('phem.state_type.backed_enum', BackedEnumType::class)
        ->tag('phem.state_type', ['priority' => 10]);

    $services->set('phem.state_type.object', ObjectType::class)
        ->args([service('phem.state_objects')])
        ->tag('phem.state_type', ['priority' => -100]);

    $services->set('phem.state_accessor', StateAccessor::class)
        ->args([service('phem.metadata'), tagged_iterator('phem.state_type')]);

    $services->set('phem.bound_values', BoundValues::class)
        ->args([service('phem.metadata'), service('phem.state_objects')]);

    $services->set('phem.url_query', UrlQuery::class)
        ->args([service('phem.metadata')]);

    $services->set('phem.state_codec', StateCodec::class)
        ->args([abstract_arg('secret, set by PhemBundle'), 86400, false]);
    $services->alias(StateCodec::class, 'phem.state_codec');

    $services->set('phem.state_validator', StateValidator::class)
        ->args([
            service('validator')->nullOnInvalid(),
            service('phem.metadata'),
            service('phem.state_accessor'),
        ]);

    $services->set('phem.state_copies', StateCopies::class)
        ->args([service('phem.metadata'), service('phem.state_objects')]);

    $services->set('phem.state_cache', StateCache::class)
        ->args([service(StateCache::POOL), 4096]);

    $services->set('phem.state_size_limit', StateSizeLimit::class)
        ->args([1048576, param('kernel.debug'), service('logger')->nullOnInvalid()]);

    /*
     * Path values: the converters of the #[Path] props
     */

    $services->set('phem.path_resolver', PathResolver::class)
        ->args([service('phem.metadata'), tagged_iterator('phem.path_converter')]);

    $services->set('phem.path_converter.scalar', ScalarPathConverter::class)
        ->tag('phem.path_converter', ['priority' => -100]);

    $services->set('phem.path_converter.entity', EntityPathConverter::class)
        ->args([service('phem.entity_finder')->nullOnInvalid()])
        ->tag('phem.path_converter', ['priority' => -100]);

    /*
     * Arguments: the function pages and the event handlers get them as a Symfony controller
     */

    $services->set('phem.callable_arguments', CallableArguments::class)
        ->args([service('argument_resolver')]);

    // Before all the other resolvers: the arguments of a handler call in the markup.
    $services->set('phem.argument_resolver.call', CallValueResolver::class)
        ->tag('controller.argument_value_resolver', ['priority' => 200, 'name' => CallValueResolver::class]);

    $services->set('phem.argument_resolver.event', EventValueResolver::class)
        ->tag('controller.argument_value_resolver', ['priority' => 150, 'name' => EventValueResolver::class]);

    // The object of the form that the map_form middleware mapped and validated.
    $services->set('phem.argument_resolver.map_form', MapFormValueResolver::class)
        ->args([service('phem.form_mapper')])
        ->tag('controller.argument_value_resolver', ['priority' => 150, 'name' => MapFormValueResolver::class]);

    $services->set('phem.form_mapper', FormMapper::class)
        ->args([service('serializer')->nullOnInvalid(), service('validator')->nullOnInvalid()]);

    // Before the EntityValueResolver of Doctrine (110): the entity is already in the value.
    $services->set('phem.argument_resolver.path', PathValueResolver::class)
        ->tag('controller.argument_value_resolver', ['priority' => 150, 'name' => PathValueResolver::class]);

    // As the Symfony ServiceValueResolver.
    $services->set('phem.argument_resolver.service', ServiceValueResolver::class)
        ->args([abstract_arg('function pages, set by ComponentPass')])
        ->tag('controller.argument_value_resolver', ['priority' => -50, 'name' => ServiceValueResolver::class]);

    /*
     * Event handlers: the middleware around them, in the order that they run. The middleware with
     * the highest priority is the outermost one.
     */

    $services->set('phem.handler_fingerprint', HandlerFingerprint::class)
        ->args([service('phem.object_identifier')->nullOnInvalid()]);

    $services->set('phem.handler_invoker', HandlerInvoker::class)
        ->args([tagged_iterator('phem.handler_middleware'), service('phem.callable_arguments')]);

    // Inside the profiler: it sees the RedirectResponse.
    $services->set('phem.handler_middleware.redirect', RedirectMiddleware::class)
        ->args([service('phem.page_url_generator')])
        ->tag('phem.handler_middleware', ['priority' => 10]);

    // Outside the transaction: a commit that fails drops the streams too.
    $services->set('phem.handler_middleware.streams', StreamsMiddleware::class)
        ->args([service('phem.streams')])
        ->tag('phem.handler_middleware', ['priority' => 0]);

    // Before the transaction: a component with errors opens no transaction.
    $services->set('phem.handler_middleware.validation', ValidationMiddleware::class)
        ->args([service('phem.state_validator')])
        ->tag('phem.handler_middleware', ['priority' => -50]);

    // Before the transaction: a form with errors opens no transaction.
    $services->set('phem.handler_middleware.map_form', MapFormMiddleware::class)
        ->args([service('phem.form_mapper'), service('phem.state_validator')])
        ->tag('phem.handler_middleware', ['priority' => -60]);

    $services->set('phem.handler_middleware.transactional', TransactionalMiddleware::class)
        ->args([service('phem.transaction_runner')->nullOnInvalid()])
        ->tag('phem.handler_middleware', ['priority' => -100]);

    // Inside the transaction: a check or a dispatch that fails rolls back the handler.
    $services->set('phem.handler_middleware.async', AsyncMiddleware::class)
        ->args([service('phem.async.dispatcher'), service('request_stack')])
        ->tag('phem.handler_middleware', ['priority' => -200]);

    // The last one before the handler: a middleware that stops the event refreshes nothing. A
    // commit that fails drops the refresh (see the streams middleware).
    $services->set('phem.handler_middleware.broadcast', BroadcastMiddleware::class)
        ->args([
            service('phem.streams'),
            service('phem.renderer'),
            service('phem.broadcast_keys'),
            service('phem.metadata'),
        ])
        ->tag('phem.handler_middleware', ['priority' => -1000]);

    $services->set('phem.async.user', AsyncUser::class)
        ->args([
            service('security.token_storage')->nullOnInvalid(),
            service('security.user_providers')->nullOnInvalid(),
        ]);

    $services->set('phem.async.dispatcher', AsyncDispatcher::class)
        ->args([
            service('messenger.default_bus')->nullOnInvalid(),
            abstract_arg('secret, set by PhemBundle'),
            service('phem.async.user'),
        ]);

    /*
     * Browser events, toasts and announcements
     */

    $services->set('phem.browser_event_dispatcher', BrowserEventDispatcher::class)
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(BrowserEventDispatcher::class, 'phem.browser_event_dispatcher');

    $services->set('phem.toasts', Toasts::class)
        ->args([service('phem.browser_event_dispatcher')]);
    $services->alias(Toasts::class, 'phem.toasts');

    $services->set('phem.announcements', Announcements::class)
        ->args([service('phem.browser_event_dispatcher')]);
    $services->alias(Announcements::class, 'phem.announcements');

    /*
     * Rendering
     */

    $services->set('phem.component_factory', ContainerComponentFactory::class)
        ->args([abstract_arg('class components, set by ComponentPass')]);

    $services->set('phem.component_renderer.class', ClassComponentRenderer::class)
        ->args([
            service('phem.component_factory'),
            service('phem.prop_binder'),
            service('phem.state_accessor'),
            service('phem.url_query'),
            service('phem.metadata'),
            service('phem.context_resolver'),
            service('phem.feed_binder'),
        ])
        ->tag('phem.component_renderer');

    // Before the class renderer: <Suspense> is a built-in class component.
    $services->set('phem.component_renderer.suspense', SuspenseRenderer::class)
        ->args([
            service_closure('phem.renderer'),
            service('phem.prop_binder'),
            service('phem.state_accessor'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('phem.component_renderer', ['priority' => 10]);

    // Before the class renderer: <ErrorBoundary> is a built-in class component.
    $services->set('phem.component_renderer.error_boundary', ErrorBoundaryRenderer::class)
        ->args([
            service_closure('phem.renderer'),
            service('phem.prop_binder'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('phem.component_renderer', ['priority' => 10]);

    // Before the function renderer: a function page gets the arguments of a controller.
    $services->set('phem.component_renderer.function_page', FunctionPageRenderer::class)
        ->args([
            service('phem.manifest'),
            service('phem.callable_arguments'),
            service('request_stack'),
        ])
        ->tag('phem.component_renderer', ['priority' => 10]);

    $services->set('phem.component_renderer.function', FunctionComponentRenderer::class)
        ->args([service('phem.prop_binder'), service('phem.context_resolver')])
        ->tag('phem.component_renderer');

    $services->set('phem.template_renderer', TemplateComponentRenderer::class)
        ->args([service('phem.prop_binder')]);

    $services->set('phem.markup_resolver', MarkupResolver::class)
        ->args([service('twig')])
        ->tag('kernel.reset', ['method' => 'reset']);

    // The `class` attribute: the phem.class_merger option of PhemBundle selects the merger.
    $services->set('phem.class_merger.unique', UniqueClassMerger::class);
    $services->alias('phem.class_merger', 'phem.class_merger.unique');
    $services->alias(ClassMerger::class, 'phem.class_merger');

    $services->set('phem.class_attribute', ClassAttribute::class)
        ->args([service('phem.class_merger')]);

    $services->set('phem.root_element', RootElement::class)
        ->args([service('phem.class_attribute')]);

    $services->set('phem.field_errors', FieldErrors::class)
        ->args([service('phem.metadata')]);

    $services->set('phem.renderer', Renderer::class)
        ->args([
            service('phem.manifest'),
            service('phem.markup_resolver'),
            tagged_iterator('phem.component_renderer'),
            service('phem.handler_invoker'),
            service('phem.handler_fingerprint'),
            service('phem.metadata'),
            null,
            service('phem.context_resolver'),
            service('phem.broadcast_keys'),
            service('phem.root_element'),
            service('phem.field_errors'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(Renderer::class, 'phem.renderer');

    /*
     * Feeds: the lists whose items the browser keeps (see Phem\Feed)
     */

    $services->set('phem.feed_identity', FeedIdentity::class)
        ->args([service('phem.object_identifier')->nullOnInvalid()]);

    $services->set('phem.feed_item_token', FeedItemToken::class)
        ->args([abstract_arg('secret, set by PhemBundle')]);

    $services->set('phem.feed_binder', FeedBinder::class)
        ->args([
            service('phem.metadata'),
            service('phem.feed_item_token'),
            service('phem.entity_finder')->nullOnInvalid(),
            service('phem.broadcast_keys'),
        ]);

    $services->set('phem.feed_streams', FeedStreams::class)
        ->args([service('phem.feed_identity')]);

    /*
     * Streams: the push to the pages with #[Broadcast], through the Mercure hub
     */

    $services->set('phem.path_topic_values', PathTopicValues::class)
        ->args([
            service('phem.metadata'),
            service('phem.object_identifier')->nullOnInvalid(),
            service('phem.entity_finder')->nullOnInvalid(),
        ]);

    $services->set('phem.topics', Topics::class)
        ->args([
            service('phem.metadata'),
            abstract_arg('secret, set by PhemBundle'),
            service('phem.page_route_values'),
            service('phem.path_topic_values'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set('phem.sender_token', SenderToken::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set('phem.broadcast_keys', BroadcastKeys::class)
        ->args([
            abstract_arg('secret, set by PhemBundle'),
            service('phem.object_identifier')->nullOnInvalid(),
            service('phem.metadata'),
        ]);

    $services->set('phem.stream_publisher.frankenphp', FrankenPhpPublisher::class)
        ->args([service('logger')->nullOnInvalid()]);
    $services->alias('phem.stream_publisher', 'phem.stream_publisher.frankenphp');

    $services->set('phem.streams', Streams::class)
        ->args([
            service('phem.renderer'),
            service('phem.stream_publisher'),
            service('phem.topics'),
            false,
            service('phem.broadcast_keys'),
            service('phem.sender_token'),
            service('phem.feed_item_token'),
            service('phem.feed_identity'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(Streams::class, 'phem.streams');

    $services->set('phem.subscription', Subscription::class)
        ->args([
            service('phem.topics'),
            null,
            '/.well-known/mercure',
            '__Secure-mercure_access_token',
            service('logger')->nullOnInvalid(),
        ]);

    $services->set('phem.listener.stream', StreamListener::class)
        ->args([service('phem.streams'), service('phem.subscription')])
        // Before the profiler collects the data of the request (-100).
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse', 'priority' => -50])
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate'])
        ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onProcessEnd'])
        ->tag('kernel.event_listener', ['event' => 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'method' => 'onProcessEnd']);

    /*
     * Twig
     */

    $services->set('phem.twig.extension', PhemExtension::class)
        ->args([service('phem.manifest')])
        ->tag('twig.extension');

    $services->set('phem.twig.runtime', PhemRuntime::class)
        ->args([
            service('phem.renderer'),
            service('phem.template_renderer'),
            service('phem.class_attribute'),
            service('phem.feed_identity'),
            service('phem.feed_item_token'),
        ])
        ->tag('twig.runtime')
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set('phem.twig.loader', TemplateLoader::class)
        ->args(['%phem.build_dir%/templates'])
        ->tag('twig.loader');

    $services->set('phem.twig.cache_key_loader', CacheKeyLoader::class)
        ->decorate('twig.loader')
        ->args([service('.inner'), service('phem.manifest')]);

    $services->set('phem.twig.environment_configurator', EnvironmentConfigurator::class)
        ->decorate('twig.configurator.environment')
        ->args([service('.inner')]);

    $services->set('phem.twig.template_warmer', TemplateWarmer::class)
        ->args([service('twig'), service('phem.manifest')])
        ->tag('kernel.cache_warmer');

    /*
     * Routing: the routes and the URLs of the pages
     */

    $services->set('phem.route_loader', PageLoader::class)
        ->args([service('phem.manifest'), param('kernel.environment'), service('phem.metadata')])
        ->tag('routing.loader');

    // The pages in the routing.controllers resource of config/routes.yaml (Symfony 7.4+).
    $services->set('phem.route_loader.controllers', ControllersLoader::class)
        ->decorate('routing.loader.attribute.services', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner'), service('phem.route_loader')]);

    $services->set('phem.page_route_values', PageRouteValues::class)
        ->args([service('router')]);

    $services->set('phem.page_url_keys', PageUrlKeys::class)
        ->args([service('router'), '%kernel.cache_dir%', '%kernel.debug%'])
        ->tag('kernel.cache_warmer');

    $services->set('phem.page_url_values', PageUrlValues::class)
        ->args([service('phem.page_url_keys'), service('phem.object_identifier')->nullOnInvalid()]);

    $services->set('phem.page_url_generator', PageUrlGenerator::class)
        ->args([service('router'), service('phem.page_url_values')])
        // phem_path() and phem_url() in the compiled templates.
        ->tag('twig.runtime');
    $services->alias(PageUrlGenerator::class, 'phem.page_url_generator');

    /*
     * Pages: the GET request of a page
     */

    $services->set('phem.turbo_streams', TurboStreams::class);

    $services->set('phem.suspense_stream', SuspenseStream::class)
        ->args([service('phem.renderer')]);

    $services->set('phem.page_stream', PageStream::class)
        ->args([
            service('phem.suspense_stream'),
            service('request_stack')->nullOnInvalid(),
            service('translation.locale_switcher')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            param('kernel.debug'),
        ]);

    $services->set('phem.listener.page_stream', PageStreamListener::class)
        // After the session listener of Symfony (-1000).
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse', 'priority' => -1001]);

    $services->set('phem.page_renderer', PageRenderer::class)
        ->args([
            service('phem.renderer'),
            service('phem.state_codec'),
            service('phem.page_url_generator'),
            service('phem.metadata'),
            service('phem.path_resolver'),
            service('phem.state_accessor'),
            service('phem.url_query'),
            service('phem.state_cache'),
            service('phem.subscription'),
            service('phem.page_stream'),
            service('phem.state_size_limit'),
        ]);
    $services->alias(PageRenderer::class, 'phem.page_renderer');

    $services->set('phem.controller.page', PageController::class)
        ->public()
        ->args([service('phem.page_renderer')]);

    $services->set('phem.listener.page_attributes', PageAttributesListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onKernelController']);

    /*
     * Error pages
     */

    $services->set('phem.stateless_page_renderer', StatelessPageRenderer::class)
        ->args([service('phem.renderer'), service('phem.metadata'), service('phem.path_resolver')]);

    $services->set('phem.error_pages', ErrorPages::class)
        ->args([service('phem.manifest')]);

    $services->set('phem.error_pages_warmer', ErrorPagesWarmer::class)
        ->args([service('phem.error_pages'), service('phem.manifest')])
        ->tag('kernel.cache_warmer');

    // Outside the Twig renderer (priority 0): the error page, then the Twig template, then
    // the page of Symfony.
    $services->set('phem.error_page_renderer', ErrorPageRenderer::class)
        ->decorate('error_renderer.html', null, -1, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([
            service('.inner'),
            service('phem.error_pages'),
            service('phem.stateless_page_renderer'),
            service('request_stack'),
            abstract_arg('show_exceptions, set by PhemBundle'),
            param('kernel.debug'),
            service('logger')->nullOnInvalid(),
            param('kernel.charset'),
            service('router.request_context')->nullOnInvalid(),
            service('phem.metadata'),
        ]);

    $services->set('phem.listener.source_map', SourceMapListener::class)
        ->args([service('phem.source_map')])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128])
        ->tag('kernel.event_listener', ['event' => 'console.error', 'method' => 'onConsoleError', 'priority' => 128]);

    /*
     * Events: the POST request of an event in the browser
     */

    $services->set('phem.inputs_comparator', InputsComparator::class)
        ->args([service('phem.handler_fingerprint')]);

    $services->set('phem.event_renderer', EventRenderer::class)
        ->args([
            service('phem.renderer'),
            service('phem.page_url_generator'),
            service('phem.bound_values'),
            service('phem.state_accessor'),
            service('phem.inputs_comparator'),
            service('phem.metadata'),
            null,
            service('phem.state_validator'),
            service('phem.state_copies'),
        ]);
    $services->alias(EventRenderer::class, 'phem.event_renderer');

    $services->set('phem.event_body', EventBody::class)
        ->args([1048576]);

    // The uploads of the inputs with `data-phem-upload`, before their event (see UploadController).
    // The modes are in a locator: an event builds no DirectUploader.
    $services->set('phem.upload_token', UploadToken::class)
        ->args(['%kernel.secret%']);
    $services->set('phem.chunk_store', ChunkStore::class)
        ->args(['%kernel.project_dir%/var/phem_uploads', 1073741824, 2097152, 1000]);
    $services->set('phem.upload_mode.chunked', ChunkedUploadMode::class)
        ->args([service('phem.chunk_store'), service('phem.upload_token'), 2097152])
        ->tag('phem.upload_mode', ['mode' => 'chunked']);
    // The DirectUploader of the application (see DirectUploaderPass).
    $services->set('phem.upload_mode.direct', DirectUploadMode::class)
        ->args([service('phem.upload_token'), service('phem.direct_uploader')->nullOnInvalid()])
        ->tag('phem.upload_mode', ['mode' => 'direct']);
    $services->set('phem.upload_resolver', UploadResolver::class)
        ->args([service('phem.upload_token'), tagged_locator('phem.upload_mode', 'mode'), 8388608]);
    $services->set('phem.controller.upload', UploadController::class)
        ->args([tagged_locator('phem.upload_mode', 'mode'), 8388608]);

    $services->set('phem.controller.event', EventController::class)
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
            service('phem.state_cache'),
            service('phem.subscription'),
            service('phem.sender_token'),
            service('phem.state_size_limit'),
            service('phem.feed_streams'),
            service('phem.controller.upload'),
            service('phem.upload_resolver'),
        ]);

    /*
     * Console
     */

    $services->set('phem.command.warmup', WarmupCommand::class)
        ->args([service('phem.twig.template_warmer')])
        ->tag('console.command');

    $services->set('phem.command.render', RenderCommand::class)
        ->args([service('http_kernel')])
        ->tag('console.command');
};
