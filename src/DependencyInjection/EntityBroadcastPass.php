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

namespace Phem\DependencyInjection;

use Phem\Manifest\Manifest;
use Phem\Metadata\ComponentMetadata;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Gives Phem\Stream\EntityRefreshes the pages and the components of the manifest with
 * #[Broadcast(on: ...)]. With none, it removes the Doctrine listener of the refreshes: a flush
 * has no work for it.
 *
 * It runs after ComponentPass, which loads the compiled classes and functions. A class component
 * is a service, so a change of its attributes rebuilds the container. A function is not: the
 * pass watches the compiled file of each function.
 */
final class EntityBroadcastPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('phem.entity_refreshes')) {
            return;
        }

        $buildDir = $container->getParameterBag()->resolveValue($container->getParameter('phem.build_dir'));
        $manifest = new Manifest($buildDir);

        $broadcasters = [];
        if ($manifest->exists()) {
            $names = [...$manifest->pages()];
            foreach ($manifest->components() as $def) {
                $names[] = $def->fqcn;
            }

            $metadata = new ComponentMetadata();

            foreach (array_unique($names) as $name) {
                // A function is no service: its file rebuilds the container when its `on` changes.
                if (\function_exists($name) && false !== $file = new \ReflectionFunction($name)->getFileName()) {
                    $container->addResource(new FileResource($file));
                }

                if (self::hasEntities($metadata, $name)) {
                    $broadcasters[] = $name;
                }
            }
        }

        if ([] === $broadcasters) {
            $container->removeDefinition('phem.entity_refreshes');
            $container->removeDefinition('phem.entity_broadcast_listener');

            return;
        }

        $container->getDefinition('phem.entity_refreshes')->replaceArgument(3, $broadcasters);
    }

    /**
     * Whether a page or a component has #[Broadcast] with entities. An invalid `on` counts: the
     * listener tells the error at the first flush (see Phem\Doctrine\EntityBroadcastListener).
     */
    private static function hasEntities(ComponentMetadata $metadata, string $name): bool
    {
        try {
            return [] !== $metadata->entityBroadcasts($name);
        } catch (\LogicException) {
            return true;
        }
    }
}
