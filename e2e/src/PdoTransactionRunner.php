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

namespace App;

use Phem\Handler\TransactionRunner;

/**
 * Runs the #[Transactional] handlers of the e2e pages in a transaction of the Database. The e2e
 * app has no Doctrine ORM: this runner takes the place of DoctrineTransactionRunner, and the
 * middlewares of Phem around it are the same.
 */
final class PdoTransactionRunner implements TransactionRunner
{
    public function __construct(private readonly Database $database)
    {
    }

    public function run(\Closure $callback): mixed
    {
        $pdo = $this->database->connection();
        $pdo->beginTransaction();

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        $pdo->commit();

        return $result;
    }
}
