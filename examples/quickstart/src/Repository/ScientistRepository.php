<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

// The data layer: a service with the Doctrine DBAL connection injected (DoctrineBundle,
// DATABASE_URL). Components get it in their constructor: they read from it while they
// render, and their event handlers write to it. The rows are in var/data/gallery.sqlite,
// so they stay after a reload, in other browsers, and after a server restart.
final class ScientistRepository
{
    private bool $ready = false;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{id: int, name: string, profession: string, likes: int}> */
    public function all(): array
    {
        return $this->db()->fetchAllAssociative('SELECT id, name, profession, likes FROM scientist ORDER BY id');
    }

    public function add(string $name, string $profession): void
    {
        $this->db()->insert('scientist', ['name' => $name, 'profession' => $profession]);
    }

    public function like(int $id): void
    {
        $this->db()->executeStatement('UPDATE scientist SET likes = likes + 1 WHERE id = ?', [$id]);
    }

    // The first call in a process makes the database file and adds the three scientists of
    // the Gallery example.
    private function db(): Connection
    {
        if ($this->ready) {
            return $this->db;
        }
        $path = $this->db->getParams()['path'] ?? null;
        if (null !== $path && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS scientist (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(80) NOT NULL,
                profession VARCHAR(80) NOT NULL,
                likes INTEGER NOT NULL DEFAULT 0
            )
            SQL);
        if (0 === (int) $this->db->fetchOne('SELECT COUNT(*) FROM scientist')) {
            $this->db->transactional(static function (Connection $db): void {
                $db->insert('scientist', ['name' => 'Maria Skłodowska-Curie', 'profession' => 'physicist and chemist']);
                $db->insert('scientist', ['name' => 'Katsuko Saruhashi', 'profession' => 'geochemist']);
                $db->insert('scientist', ['name' => 'Lise Meitner', 'profession' => 'physicist']);
            });
        }
        $this->ready = true;

        return $this->db;
    }
}
