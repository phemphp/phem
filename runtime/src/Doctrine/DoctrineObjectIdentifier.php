<?php

declare(strict_types=1);

namespace Rephp\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Rephp\Handler\ObjectIdentifier;

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
