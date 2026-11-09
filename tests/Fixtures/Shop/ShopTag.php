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

namespace Phem\Tests\Fixtures\Shop;

use Doctrine\ORM\Mapping as ORM;

/** A tag of a ShopOrder, for the change of a collection. */
#[ORM\Entity, ORM\Table(name: 'shop_tags')]
class ShopTag
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(#[ORM\Column] public string $name = '')
    {
    }
}
