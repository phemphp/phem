<?php

declare(strict_types=1);

namespace Phem\Ui\Base;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Adds the assets of the package to AssetMapper, as `@phem/ui-base`.
 *
 * The components need no bundle. The app adds the sources of the package to its Tailwind
 * build with `@source "../../vendor/phem/ui-base/src";`.
 */
class PhemUiBaseBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Git keeps no empty folder: a package with no assets has no assets/ folder.
        if (!interface_exists(AssetMapperInterface::class) || !is_dir($this->getPath().'/assets')) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [$this->getPath().'/assets' => '@phem/ui-base'],
            ],
        ]);
    }
}
