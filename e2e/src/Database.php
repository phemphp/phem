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

/**
 * The SQLite file of the e2e pages: the requests of all the browsers share it. bin/test removes
 * it before each run, so each store creates its table when it first connects.
 */
final class Database
{
    private ?\PDO $pdo = null;
    /** @var array<string, true> the tables that this connection created */
    private array $tables = [];

    public function __construct(private readonly string $path)
    {
    }

    /** The connection, with the table of a store (`CREATE TABLE IF NOT EXISTS ...`). */
    public function connect(string $table): \PDO
    {
        $pdo = $this->pdo ??= $this->open();

        if (!isset($this->tables[$table])) {
            $pdo->exec($table);
            $this->tables[$table] = true;
        }

        return $pdo;
    }

    private function open(): \PDO
    {
        if (!is_dir(\dirname($this->path))) {
            mkdir(\dirname($this->path), 0o777, true);
        }

        return new \PDO('sqlite:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
