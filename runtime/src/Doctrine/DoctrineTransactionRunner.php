<?php

declare(strict_types=1);

namespace Rephp\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Rephp\Handler\TransactionRunner;

/**
 * Runs a handler in a transaction of the default entity manager. `wrapInTransaction()` flushes
 * and commits after the handler. When the handler throws, it rolls back and closes the entity
 * manager (DoctrineBundle resets it for the next request).
 */
final class DoctrineTransactionRunner implements TransactionRunner
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function run(\Closure $callback): mixed
    {
        $em = $this->doctrine->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('#[Transactional] needs a Doctrine ORM entity manager, "%s" given.', get_debug_type($em)));
        }

        return $em->wrapInTransaction(static fn (): mixed => $callback());
    }
}
