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

use Phem\Async\AsyncHandler;
use Phem\Async\AsyncHandlerMessage;
use Phem\Handler\HandlerInvoker;

// The #[Async] handlers: their work runs in a Messenger worker.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The middleware of the handlers, with no AsyncMiddleware (the worker runs the work), no
    // redirect (no browser waits for the worker), no rate limit and no validation (the event
    // had them) and no profiler (a worker has no profile).
    $services->set('phem.async.handler_invoker', HandlerInvoker::class)
        ->args([
            tagged_iterator('phem.handler_middleware', exclude: [
                'phem.handler_middleware.async',
                'phem.handler_middleware.redirect',
                'phem.handler_middleware.rate_limit',
                'phem.handler_middleware.validation',
                'phem.handler_middleware.profiler',
            ]),
            service('phem.callable_arguments'),
        ]);

    $services->set('phem.async.handler', AsyncHandler::class)
        ->public()
        ->args([
            service('phem.event_renderer'),
            service('phem.renderer'),
            service('phem.async.handler_invoker'),
            service('phem.streams'),
            abstract_arg('secret, set by PhemBundle'),
            service('phem.path_resolver'),
            service('phem.async.user'),
            service('request_stack')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            service('phem.browser_event_dispatcher'),
        ])
        ->tag('messenger.message_handler', ['handles' => AsyncHandlerMessage::class]);
};
