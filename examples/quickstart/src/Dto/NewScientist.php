<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class NewScientist
{
    #[Assert\NotBlank(message: 'Type a name.')]
    #[Assert\Length(min: 2, max: 80)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Type a profession.')]
    #[Assert\Length(min: 2, max: 80)]
    public ?string $profession = null;
}
