<?php

declare(strict_types=1);

namespace Rephp\Handler;

use Rephp\Attribute\Transactional;
use Rephp\Event;

/**
 * Runs the event handlers of a `#[Transactional]` component in a transaction of the
 * TransactionRunner (Doctrine ORM, when DoctrineBundle is installed).
 */
final class TransactionalMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly ?TransactionRunner $transactions = null,
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        if (!self::isTransactional($handler)) {
            return $next();
        }
        $transactions = $this->transactions ?? throw new \LogicException('A #[Transactional] component needs Doctrine ORM: install doctrine/orm and doctrine/doctrine-bundle.');

        return $transactions->run($next);
    }

    /**
     * The component of a handler is the object of its closure: a method (`$this->add(...)`) or
     * a closure in `render()`. A handler that a parent passes as a prop runs with the parent.
     */
    private static function isTransactional(\Closure $handler): bool
    {
        $component = new \ReflectionFunction($handler)->getClosureThis();

        return null !== $component && [] !== new \ReflectionObject($component)->getAttributes(Transactional::class);
    }
}
