<?php

declare(strict_types=1);

namespace Rephp\Doctrine;

use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use Rephp\Path\EntityFinder;

/**
 * Finds the entities of the `#[Path]` props with the entity manager of their class, as the
 * Doctrine `EntityValueResolver` does for controller arguments: an identifier goes to `find()`,
 * and criteria go to `findOneBy()`.
 */
final class DoctrineEntityFinder implements EntityFinder
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function supports(string $class): bool
    {
        return (class_exists($class) || interface_exists($class)) && null !== $this->doctrine->getManagerForClass($class);
    }

    public function find(string $class, mixed $id): ?object
    {
        $manager = $this->doctrine->getManagerForClass($class) ?? throw new \LogicException(\sprintf('No Doctrine entity manager has the class "%s".', $class));
        $repository = $manager->getRepository($class);
        try {
            return \is_array($id) ? $repository->findOneBy($id) : $repository->find($id);
        } catch (NoResultException|ConversionException) {
            return null;
        }
    }
}
