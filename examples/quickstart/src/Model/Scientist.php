<?php

namespace App\Model;

use App\Repository\ScientistRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

#[Entity(repositoryClass: ScientistRepository::class)]
class Scientist
{
    #[Id, GeneratedValue, Column]
    public ?int $id = null;

    public function __construct(
        #[Column(length: 80)]
        public string $name,

        #[Column(length: 80)]
        public string $profession,

        #[Column(options: ['default' => 0])]
        public int $likes = 0,
    ) {
    }
}
