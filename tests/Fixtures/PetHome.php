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

/** A nested object of a draft (see PetDraft). */
final class PetHome
{
    #[Assert\Length(min: 2, minMessage: 'Too short.')]
    public string $city = 'Oslo';
}
