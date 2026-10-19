<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Infrastructure\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Entry point for subscription-engine schema migrations.
 *
 * - Fresh install: runs `database/schema.sql` (the latest snapshot) and marks
 *   every migration as a baseline so it is not executed again.
 * - Existing database: runs only the incremental migrations that have not been
 *   applied, without touching existing data. `schema.sql` is skipped so its
 *   definitions are not duplicated by migrations.
 */
class DatabaseMigrator
{
    public function __construct(private readonly PDO $pdo) {}

    public function migrate(): void
    {
        $runner = new MigrationRunner($this->pdo);

        // Detect before exec: a fresh install has no subscriptions table yet.
        if (! $this->tableExists('subscriptions')) {
            $sql = file_get_contents(__DIR__.'/../../database/schema.sql');
            if ($sql === false) {
                throw new RuntimeException('Unable to load subscription-engine schema.sql file.');
            }

            $this->pdo->exec($sql);

            // Fresh install: schema.sql already reflects the latest schema, so
            // mark every migration as a baseline to avoid executing them again.
            $runner->markAllAsMigrated();

            return;
        }

        // Existing database: run the incremental migrations that are still pending.
        $runner->migrate();
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (Throwable) {
            return false;
        }

        return $stmt !== false;
    }
}
