<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phem\Tests\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

/** A draft object in a #[State] property (see the `draft` fixture page). */
final class PetDraft
{
    #[Assert\NotBlank(message: 'Give a name.')]
    public string $name = 'Rex';

    public ?int $age = null;

    public Size $size = Size::Small;

    public ?\DateTimeImmutable $born = null;

    #[Assert\Valid]
    public PetHome $home;

    public readonly string $kind;

    public function __construct()
    {
        $this->home = new PetHome();
        $this->kind = 'dog';
    }

    /** A getter: the state does not keep its value. */
    public function getLabel(): string
    {
        return $this->name.' ('.$this->kind.')';
    }

    public function isAdult(): bool
    {
        return null !== $this->age && $this->age >= 2;
    }
}
