<?php

namespace App\Repository;

use App\Model\Report;
use App\Model\Scientist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
final class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    /**
     * @return list<Report> the newest first
     */
    public function latest(int $limit = 10): array
    {
        return $this->findBy([], ['id' => 'DESC'], $limit);
    }

    /**
     * Builds the report of a ticket, or finds it: the worker can run the work again (a retry).
     * The transaction of the worker (#[Transactional]) flushes it.
     */
    public function build(string $ticket): Report
    {
        if (null !== $report = $this->findOneBy(['ticket' => $ticket])) {
            return $report;
        }

        $scientists = $this->getEntityManager()->getRepository(Scientist::class)->findAll();
        $professions = array_unique(array_map(static fn (Scientist $s): string => $s->profession, $scientists));
        $report = new Report($ticket, \count($scientists), mb_substr(implode(', ', $professions), 0, 80));

        $this->getEntityManager()->persist($report);

        return $report;
    }
}
