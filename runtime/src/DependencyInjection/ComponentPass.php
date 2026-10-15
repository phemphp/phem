<?php

declare(strict_types=1);

namespace Rephp\DependencyInjection;

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers each `#[Component]` class of the rhpc manifest as a service: autowired,
 * autoconfigured, and not shared (each component instance is a new object). The Renderer gets
 * them through a service locator.
 *
 * An application can define a component service itself (for example to bind an argument);
 * the pass keeps that definition and only makes it not shared.
 *
 * In debug mode, the container is rebuilt when `rhpc build` writes a new manifest.
 */
final class ComponentPass implements CompilerPassInterface
{
    public const TAG = 'rephp.component';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('rephp.renderer')) {
            return;
        }
        $buildDir = $container->getParameterBag()->resolveValue($container->getParameter('rephp.build_dir'));
        $manifestFile = $buildDir.'/manifest.php';
        $container->addResource(new FileExistenceResource($manifestFile));

        $components = [];
        if (is_file($manifestFile)) {
            $container->addResource(new FileResource($manifestFile));
            $manifest = require $manifestFile;
            // The classes must exist for autowiring. They are not PSR-4 classes.
            require_once $buildDir.'/autoload.php';

            foreach ($manifest['components'] ?? [] as $fqcn => $def) {
                if ('class' !== ($def['kind'] ?? null)) {
                    continue;
                }
                $definition = $container->hasDefinition($fqcn)
                    ? $container->getDefinition($fqcn)
                    : $container->register($fqcn, $fqcn)->setAutowired(true)->setAutoconfigured(true);
                $definition->setShared(false)->addTag(self::TAG);
                $components[$fqcn] = new Reference($fqcn);
            }
        }

        $container->getDefinition('rephp.renderer')->replaceArgument(2, ServiceLocatorTagPass::register($container, $components));
    }
}
