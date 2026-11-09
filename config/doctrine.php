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

use Phem\Doctrine\DoctrineEntityFinder;
use Phem\Doctrine\DoctrineObjectIdentifier;
use Phem\Doctrine\DoctrineTransactionRunner;
use Phem\Doctrine\EntityBroadcastListener;
use Phem\Stream\EntityRefreshes;

// The Doctrine ORM adapters. PhemBundle imports this file when DoctrineBundle is installed:
// the #[Transactional] event handlers run in a transaction, the #[Path] props with an
// entity type get their entity, a handler that captures an entity keeps its id, and a flush
// refreshes the pages and the components with #[Broadcast(on: ...)] (see EntityBroadcastPass).
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('phem.transaction_runner', DoctrineTransactionRunner::class)
        ->args([service('doctrine')]);

    $services->set('phem.entity_finder', DoctrineEntityFinder::class)
        ->args([service('doctrine')]);

    $services->set('phem.object_identifier', DoctrineObjectIdentifier::class)
        ->args([service('doctrine')]);

    $services->set('phem.entity_refreshes', EntityRefreshes::class)
        ->args([
            service('phem.streams'),
            service('phem.topics'),
            service('phem.broadcast_keys'),
            abstract_arg('the pages and the components with #[Broadcast(on: ...)], set by EntityBroadcastPass'),
            service('phem.metadata'),
        ]);

    $services->set('phem.entity_broadcast_listener', EntityBroadcastListener::class)
        ->args([
            service('phem.entity_refreshes'),
            service('doctrine'),
            service('phem.sender_token'),
        ])
        // After the other onFlush listeners: their changes are in the unit of work.
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => -1024])
        ->tag('doctrine.event_listener', ['event' => 'postFlush'])
        ->tag('kernel.reset', ['method' => 'reset']);
};
