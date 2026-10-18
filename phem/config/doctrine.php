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

// The Doctrine ORM adapters. PhemBundle imports this file when DoctrineBundle is installed:
// the event handlers run in a transaction (see the `phem.transactional` option), the #[Path] props with an
// entity type get their entity, and a handler that captures an entity keeps its id.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('phem.transaction_runner', DoctrineTransactionRunner::class)
            ->args([service('doctrine')])
        ->set('phem.entity_finder', DoctrineEntityFinder::class)
            ->args([service('doctrine')])
        ->set('phem.object_identifier', DoctrineObjectIdentifier::class)
            ->args([service('doctrine')])
    ;
};
