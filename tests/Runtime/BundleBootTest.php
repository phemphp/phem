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

namespace Phem\Tests\Runtime;

use Phem\PhemBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

final class BundleBootTest extends TestCase
{
    public function testTheBundleBootsWithAContainerThatHasNoPhemServices(): void
    {
        // cache:clear can boot with the container of the app before Flex added the bundle.
        $bundle = new PhemBundle();
        $bundle->setContainer(new Container());

        $bundle->boot();

        $this->addToAssertionCount(1);
    }
}
