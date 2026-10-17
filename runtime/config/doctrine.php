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

use Mamu\Doctrine\DoctrineEntityFinder;
use Mamu\Doctrine\DoctrineObjectIdentifier;
use Mamu\Doctrine\DoctrineTransactionRunner;

// The Doctrine ORM adapters. MamuBundle imports this file when DoctrineBundle is installed:
// the handlers of #[Transactional] components run in a transaction, the #[Path] props with an
// entity type get their entity, and a handler that captures an entity keeps its id.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('mamu.transaction_runner', DoctrineTransactionRunner::class)
            ->args([service('doctrine')])
        ->set('mamu.entity_finder', DoctrineEntityFinder::class)
            ->args([service('doctrine')])
        ->set('mamu.object_identifier', DoctrineObjectIdentifier::class)
            ->args([service('doctrine')])
    ;
};
