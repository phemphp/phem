<?php

namespace App\Repository;

use App\Model\GuestbookEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestbookEntry>
 */
final class GuestbookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestbookEntry::class);
    }

    /**
     * @return list<GuestbookEntry> the newest first
     */
    public function latest(int $limit = 20): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }

    /**
     * The entry is in the database after the transaction of the event: flush now, so it has an id
     * for the markup of the push.
     */
    public function add(GuestbookEntry $entry): void
    {
        $this->getEntityManager()->persist($entry);
    }

    /**
     * @return list<string> the names of the people who signed, the newest first
     */
    public function names(): array
    {
        return array_values(array_unique(array_map(static fn (GuestbookEntry $e): string => $e->name, $this->latest(100))));
    }
}
