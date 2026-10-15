<?php

namespace App\Repository;

use App\Model\Scientist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;

// The data layer: a Doctrine ORM repository for the Scientist entity (DoctrineBundle,
// DATABASE_URL). Components get it in their constructor: they read from it while they
// render, and their event handlers write to it. The rows are in var/data/gallery.sqlite,
// so they stay after a reload, in other browsers, and after a server restart.
/** @extends ServiceEntityRepository<Scientist> */
final class ScientistRepository extends ServiceEntityRepository
{
    private bool $ready = false;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scientist::class);
    }

    /** @return list<Scientist> */
    public function all(): array
    {
        $this->ready();

        return $this->findBy([], ['id' => 'ASC']);
    }

    public function add(Scientist $scientist): void
    {
        $this->ready();
        $this->getEntityManager()->persist($scientist);
        $this->getEntityManager()->flush();
    }

    // One UPDATE statement, so two clicks at the same time both count. The UPDATE skips the
    // loaded entities, so a scientist that this request already read is read again.
    public function like(int $id): void
    {
        $this->ready();
        $em = $this->getEntityManager();
        $em->createQuery('UPDATE '.Scientist::class.' s SET s.likes = s.likes + 1 WHERE s.id = :id')
            ->setParameter('id', $id)
            ->execute();
        if ($scientist = $em->getUnitOfWork()->tryGetById($id, Scientist::class)) {
            $em->refresh($scientist);
        }
    }

    // The first call in a process makes the database file and the table from the entity
    // mapping, and adds the three scientists of the Gallery example.
    private function ready(): void
    {
        if ($this->ready) {
            return;
        }
        $em = $this->getEntityManager();
        $db = $em->getConnection();
        $path = $db->getParams()['path'] ?? null;
        if (null !== $path && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        if (!$db->createSchemaManager()->tablesExist(['scientist'])) {
            new SchemaTool($em)->createSchema([$em->getClassMetadata(Scientist::class)]);
        }
        if (0 === $this->count()) {
            $em->wrapInTransaction(static function (EntityManagerInterface $em): void {
                $em->persist(new Scientist('Maria Skłodowska-Curie', 'physicist and chemist'));
                $em->persist(new Scientist('Katsuko Saruhashi', 'geochemist'));
                $em->persist(new Scientist('Lise Meitner', 'physicist'));
            });
        }
        $this->ready = true;
    }
}
