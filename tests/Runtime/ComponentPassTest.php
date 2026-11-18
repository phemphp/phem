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

use Phem\DependencyInjection\ComponentPass;
use Phem\Event;
use Phem\Reply;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\TypedReference;

final class ComponentPassTest extends TestCase
{
    public function testAFunctionPageGetsNoServiceForTheEventAndTheReply(): void
    {
        // A page gets no event and no reply: the value resolvers report the parameters, not a
        // missing service.
        $page = static function (Event $e, Reply $reply, LoggerInterface $logger): void {};

        $services = new \ReflectionMethod(ComponentPass::class, 'services')->invoke(null, new ContainerBuilder(), new \ReflectionFunction($page));

        self::assertSame(['logger'], array_keys($services));
        self::assertInstanceOf(TypedReference::class, $services['logger']);
    }
}
