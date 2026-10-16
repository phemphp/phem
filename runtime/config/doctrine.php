<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Rephp\Doctrine\DoctrineEntityFinder;
use Rephp\Doctrine\DoctrineTransactionRunner;

// The Doctrine ORM adapters. RephpBundle imports this file when DoctrineBundle is installed:
// the handlers of #[Transactional] components run in a transaction, and the #[Path] props
// with an entity type get their entity.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('rephp.transaction_runner', DoctrineTransactionRunner::class)
            ->args([service('doctrine')])
        ->set('rephp.entity_finder', DoctrineEntityFinder::class)
            ->args([service('doctrine')])
    ;
};
