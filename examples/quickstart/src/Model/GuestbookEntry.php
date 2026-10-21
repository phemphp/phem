<?php

namespace App\Model;

use App\Repository\GuestbookRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

#[Entity(repositoryClass: GuestbookRepository::class)]
class GuestbookEntry
{
    #[Id, GeneratedValue, Column]
    public ?int $id = null;

    #[Column]
    public \DateTimeImmutable $signedAt;

    public function __construct(
        #[Column(length: 40)]
        public string $name,

        #[Column(length: 280)]
        public string $message,
    ) {
        $this->signedAt = new \DateTimeImmutable();
    }
}
