<?php

namespace App\Repository;

use App\Model\Scientist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Scientist>
 */
final class ScientistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scientist::class);
    }

    /**
     * @return list<Scientist>
     */
    public function all(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    public function add(Scientist $scientist): void
    {
        $this->getEntityManager()->persist($scientist);
    }

    public function remove(Scientist $scientist): void
    {
        $this->getEntityManager()->remove($scientist);
    }
}
