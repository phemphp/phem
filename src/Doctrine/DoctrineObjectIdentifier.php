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

namespace Phem\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Phem\Handler\ObjectIdentifier;

/**
 * Identifies an entity by its identifier values, from the metadata of its entity manager. It
 * does not load a lazy entity.
 */
final class DoctrineObjectIdentifier implements ObjectIdentifier
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function identify(object $value): ?array
    {
        $manager = $this->doctrine->getManagerForClass($value::class);

        return $manager?->getClassMetadata($value::class)->getIdentifierValues($value);
    }
}
