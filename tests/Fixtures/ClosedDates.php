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

/** The object of a #[MapForm] form (see the `map_form` fixture page). */
final class ClosedDates
{
    #[Assert\NotNull(message: 'Choose the first day.')]
    public ?\DateTimeImmutable $from = null;

    public ?\DateTimeImmutable $to = null;

    public ?Size $size = null;

    #[Assert\Positive]
    public int $guests = 1;

    public bool $notify = false;
}
