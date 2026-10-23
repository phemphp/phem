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

namespace Phem\Async;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The user of an #[Async] event in the worker: the same user as in the request.
 *
 * The request serializes the security token, as the session does. The worker unserializes it,
 * loads the user again with the user provider (as the ContextListener of a firewall does), and
 * sets the token while the handler runs. With no SecurityBundle, there is no user.
 */
final class AsyncUser
{
    /** @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface>|null $users */
    public function __construct(
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?UserProviderInterface $users = null,
    ) {
    }

    /** The token of the user now, serialized, or null with no user. */
    public function capture(): ?string
    {
        $token = $this->tokens?->getToken();

        return null !== $token ? serialize($token) : null;
    }

    /**
     * Runs a callback with the user of a token of capture().
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     *
     * @throws UnrecoverableMessageHandlingException when the user does not exist any more
     */
    public function run(?string $token, \Closure $callback): mixed
    {
        if (null === $token || null === $this->tokens) {
            return $callback();
        }

        // The message has a valid HMAC (see AsyncHandler): the token comes from capture().
        $user = unserialize($token);
        if (!$user instanceof TokenInterface) {
            throw new UnrecoverableMessageHandlingException('The token of an async handler is not a security token.');
        }

        $this->refresh($user);

        $previous = $this->tokens->getToken();
        $this->tokens->setToken($user);

        try {
            return $callback();
        } finally {
            $this->tokens->setToken($previous);
        }
    }

    private function refresh(TokenInterface $token): void
    {
        $user = $token->getUser();
        if (null === $user || null === $this->users || !$this->users->supportsClass($user::class)) {
            return;
        }

        try {
            $token->setUser($this->users->refreshUser($user));
        } catch (UserNotFoundException $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The user "%s" of an async handler does not exist any more.', $user->getUserIdentifier()), 0, $e);
        }
    }
}
