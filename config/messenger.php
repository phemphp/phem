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

return static function (ContainerConfigurator $container): void {
    $container->services()
        // The middleware of the handlers, with no AsyncMiddleware (the worker runs the work), no
        // rate limit (the event had it) and no profiler (a worker has no profile).
        ->set('phem.async.handler_invoker', HandlerInvoker::class)
            ->args([tagged_iterator('phem.handler_middleware', exclude: ['phem.handler_middleware.async', 'phem.handler_middleware.rate_limit', 'phem.handler_middleware.profiler'])])
        ->set('phem.async.handler', AsyncHandler::class)
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
            ])
            ->tag('messenger.message_handler', ['handles' => AsyncHandlerMessage::class])
    ;
};
