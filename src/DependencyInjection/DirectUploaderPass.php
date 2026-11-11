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

use Phem\Upload\DirectUploader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds the DirectUploader of the application for `data-phem-upload="direct"`: the service with
 * the id or the alias `Phem\Upload\DirectUploader`, or else the one autoconfigured service that
 * implements the interface. The `phem.direct_uploader` alias points to it. With no service, a
 * direct upload gets a 400.
 */
final class DirectUploaderPass implements CompilerPassInterface
{
    /** The tag of the autoconfigured implementations. */
    public const TAG = 'phem.direct_uploader';

    public function process(ContainerBuilder $container): void
    {
        if ($container->has(DirectUploader::class)) {
            $container->setAlias(self::TAG, DirectUploader::class);

            return;
        }

        $ids = array_keys($container->findTaggedServiceIds(self::TAG));
        if (\count($ids) > 1) {
            throw new \LogicException(\sprintf('More than one service implements %s (%s): give the alias "%1$s" to the service of the direct uploads.', DirectUploader::class, implode(', ', $ids)));
        }

        if (1 === \count($ids)) {
            $container->setAlias(self::TAG, $ids[0]);
        }
    }
}
