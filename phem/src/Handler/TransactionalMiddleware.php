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
 * Runs the event handlers of a `#[Transactional]` component in a transaction of the
 * TransactionRunner (Doctrine ORM, when DoctrineBundle is installed). With `$all`, it runs
 * all event handlers in a transaction, with or without the attribute.
 */
final class TransactionalMiddleware implements HandlerMiddleware
{
    public function __construct(
        private readonly ?TransactionRunner $transactions = null,
        private readonly bool $all = false,
    ) {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        if (!$this->all && !self::isTransactional($handler)) {
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
