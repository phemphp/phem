<?php

declare(strict_types=1);

namespace Phem\Ui\Base\Tests;

use Phem\Test\TestKernel;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class Kernel extends TestKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->services()->set('logger', NullLogger::class);
    }
}
