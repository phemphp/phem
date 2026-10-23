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

use Phem\Async\AsyncUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;

final class AsyncUserTest extends TestCase
{
    public function testTheWorkerRunsWithTheUserOfTheRequest(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null, ['ROLE_USER']), 'main', ['ROLE_USER']));
        $token = new AsyncUser($tokens)->capture();
        $tokens->setToken(null);

        // The provider has the user now: the worker gets the new roles.
        $users = new InMemoryUserProvider(['ada' => ['roles' => ['ROLE_ADMIN']]]);
        $user = new AsyncUser($tokens, $users)->run($token, static fn (): ?array => $tokens->getToken()?->getUser()?->getRoles());

        self::assertSame(['ROLE_ADMIN'], $user);
        self::assertNull($tokens->getToken(), 'the worker forgets the user after the work');
    }

    public function testWithNoUserTheWorkRunsWithNoToken(): void
    {
        $tokens = new TokenStorage();

        self::assertNull(new AsyncUser($tokens)->capture());
        self::assertNull(new AsyncUser($tokens)->run(null, static fn (): ?object => $tokens->getToken()));
    }

    public function testAUserThatDoesNotExistAnyMoreDropsTheMessage(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main'));
        $token = new AsyncUser($tokens)->capture();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        new AsyncUser($tokens, new InMemoryUserProvider())->run($token, static fn (): null => null);
    }
}
