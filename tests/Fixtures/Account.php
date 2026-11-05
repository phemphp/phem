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
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** The data of the Symfony form of form.phem (see AccountType). */
final class Account
{
    #[Assert\NotBlank, Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank, Assert\Length(min: 8)]
    public ?string $password = null;

    #[Assert\NotBlank(message: 'Enter a city.')]
    public ?string $city = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'Choose a tag.')]
    public array $tags = [];

    /** @var list<string|null> */
    public array $phones = [];

    // A constraint on the class: its error goes on the root form.
    #[Assert\Callback]
    public function knownCity(ExecutionContextInterface $context): void
    {
        if ('Nowhere' === $this->city) {
            $context->buildViolation('We do not ship to Nowhere.')->addViolation();
        }
    }
}
