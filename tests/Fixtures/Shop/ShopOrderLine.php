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

/** A line of a ShopOrder: its to-one association selects the page of the order. */
#[ORM\Entity, ORM\Table(name: 'shop_order_lines')]
class ShopOrderLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lines')] #[ORM\JoinColumn(nullable: false)] public ShopOrder $order,
        #[ORM\Column] public int $quantity = 1,
    ) {
    }
}
