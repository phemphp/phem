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

namespace Phem\Handler;

use Phem\Attribute\Transactional;
use Phem\Event;

/**
 * Runs the event handlers of a `#[Transactional]` component, and the `#[Transactional]` handler
 * methods, in a transaction of the TransactionRunner (Doctrine ORM, when DoctrineBundle is installed). The other handlers run
 * without a transaction.
 */
final class TransactionalMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly ?TransactionRunner $transactions = null,
        private readonly HandlerAttributes $attributes = new HandlerAttributes(),
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        if (!$this->isTransactional($handler)) {
            return $next();
        }

        $transactions = $this->transactions ?? throw new \LogicException('A #[Transactional] handler needs Doctrine ORM: install doctrine/orm and doctrine/doctrine-bundle.');

        return $transactions->run($next);
    }

    /**
     * The component of a handler is the object of its closure: a method (`$this->add(...)`) or
     * a closure in `render()`. A handler that a parent passes as a prop runs with the parent.
     * The attribute is on the class of the component, or on the method of the handler.
     */
    private function isTransactional(\Closure $handler): bool
    {
        $component = new \ReflectionFunction($handler)->getClosureThis();
        if (null === $component) {
            return false;
        }

        if ([] !== new \ReflectionObject($component)->getAttributes(Transactional::class)) {
            return true;
        }

        return [] !== $this->attributes->of($handler, Transactional::class);
    }
}
