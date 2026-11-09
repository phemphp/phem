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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** An entity with lines and tags, for the entity broadcasts (see Phem\Stream\EntityRefreshes). */
#[ORM\Entity, ORM\Table(name: 'shop_orders')]
class ShopOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, ShopTag> */
    #[ORM\ManyToMany(targetEntity: ShopTag::class)]
    public Collection $tags;

    /** @var Collection<int, ShopOrderLine> the inverse side: a flush never writes it */
    #[ORM\OneToMany(targetEntity: ShopOrderLine::class, mappedBy: 'order')]
    public Collection $lines;

    public function __construct(
        #[ORM\Column] public string $status = 'new',
        #[ORM\Column] public string $note = '',
    ) {
        $this->tags = new ArrayCollection();
        $this->lines = new ArrayCollection();
    }
}
