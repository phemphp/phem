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

namespace Phem\Rendering;

use Psr\Container\ContainerInterface;

/**
 * Class components are services: the container makes a new instance for each fiber (the
 * constructor gets the dependencies).
 */
final class ContainerComponentFactory implements ComponentFactory
{
    /**
     * @param ContainerInterface $components class component FQCN => service (not shared), set by ComponentPass
     */
    public function __construct(private readonly ContainerInterface $components)
    {
    }

    public function create(string $fqcn): object
    {
        if (!$this->components->has($fqcn)) {
            throw new \LogicException(\sprintf('The component "%s" is not a service. Run `bin/console cache:clear` after `phemc build`.', $fqcn));
        }

        return $this->components->get($fqcn);
    }
}
