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

namespace Mamu\DependencyInjection;

use Mamu\Manifest\ComponentKind;
use Mamu\Manifest\Manifest;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers each `#[Component]` class of the mamuc manifest as a service: autowired,
 * autoconfigured, and not shared (each component instance is a new object). The
 * ContainerComponentFactory gets them through a service locator.
 *
 * An application can define a component service itself (for example to bind an argument);
 * the pass keeps that definition and only makes it not shared.
 *
 * In debug mode, the container is rebuilt when `mamuc build` writes a new manifest.
 */
final class ComponentPass implements CompilerPassInterface
{
    public const TAG = 'mamu.component';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mamu.component_factory')) {
            return;
        }
        $buildDir = $container->getParameterBag()->resolveValue($container->getParameter('mamu.build_dir'));
        $manifestFile = $buildDir.'/manifest.php';
        $container->addResource(new FileExistenceResource($manifestFile));

        $components = [];
        $manifest = new Manifest($buildDir, '');
        if ($manifest->exists()) {
            $container->addResource(new FileResource($manifestFile));
            // The manifest also loads the classes: they must exist for autowiring. They are not PSR-4 classes.
            foreach ($manifest->components() as $def) {
                if (ComponentKind::ClassComponent !== $def->kind) {
                    continue;
                }
                $fqcn = $def->fqcn;
                $definition = $container->hasDefinition($fqcn)
                    ? $container->getDefinition($fqcn)
                    : $container->register($fqcn, $fqcn)->setAutowired(true)->setAutoconfigured(true);
                $definition->setShared(false)->addTag(self::TAG);
                $components[$fqcn] = new Reference($fqcn);
            }
        }

        $container->getDefinition('mamu.component_factory')->replaceArgument(0, ServiceLocatorTagPass::register($container, $components));
    }
}
