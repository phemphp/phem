<?php

namespace App\Model;

use App\Repository\ReportRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

// A report of the Reports example: the worker of an #[Async] handler builds it.
#[Entity(repositoryClass: ReportRepository::class)]
class Report
{
    #[Id, GeneratedValue, Column]
    public ?int $id = null;

    #[Column]
    public \DateTimeImmutable $builtAt;

    public function __construct(
        // The ticket of the click: a retry of the worker finds the same report.
        #[Column(length: 16, unique: true)]
        public string $ticket,

        #[Column]
        public int $scientists,

        #[Column(length: 80)]
        public string $professions,
    ) {
        $this->builtAt = new \DateTimeImmutable();
    }
}
