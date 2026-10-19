<?php

declare(strict_types=1);

use Ttpryg\SubscriptionEngine\Infrastructure\Database\Migration;

/**
 * Add performance indexes used by the subscription engine repository adapters.
 *
 * Fresh installs get these indexes from `database/schema.sql`; this migration
 * upgrades databases created before the indexes existed.
 *
 * The statements are built explicitly (instead of `CREATE INDEX IF NOT EXISTS`)
 * because MySQL does not support `IF NOT EXISTS` for `CREATE INDEX`.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{table: string, columns: string}>
     */
    private const INDEXES = [
        'idx_subs_user_store' => ['table' => 'subscriptions', 'columns' => 'user_id, store_id'],
        'idx_subs_renewal' => ['table' => 'subscriptions', 'columns' => 'status, current_period_end'],
        'idx_inv_subscription' => ['table' => 'subscription_invoices', 'columns' => 'subscription_id'],
        'idx_inv_overdue' => ['table' => 'subscription_invoices', 'columns' => 'status, due_at'],
        'idx_plans_active' => ['table' => 'subscription_plans', 'columns' => 'is_active, store_id'],
    ];

    /**
     * Indexes backing a foreign key on MySQL/MariaDB. InnoDB refuses to drop
     * them while the constraint exists, so `down()` leaves them in place.
     * Keeping an index is data-preserving, so the rollback remains safe.
     *
     * @var array<int, string>
     */
    private const FOREIGN_KEY_INDEXES = ['idx_inv_subscription'];

    public function up(PDO $pdo): void
    {
        foreach (self::INDEXES as $name => $index) {
            if ($this->indexExists($pdo, $index['table'], $name)) {
                continue;
            }

            $pdo->exec(sprintf('CREATE INDEX %s ON %s (%s)', $name, $index['table'], $index['columns']));
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach (array_reverse(self::INDEXES, true) as $name => $index) {
            if (! $this->indexExists($pdo, $index['table'], $name)) {
                continue;
            }

            if ($driver === 'mysql' && in_array($name, self::FOREIGN_KEY_INDEXES, true)) {
                continue;
            }

            $pdo->exec($this->dropIndexStatement($pdo, $index['table'], $name));
        }
    }

    public function previouslyRecordedAs(): array
    {
        return ['001_add_subscription_indexes'];
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = :index");
            $stmt->execute(['index' => $index]);

            return (int) $stmt->fetchColumn() > 0;
        }

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
            );
            $stmt->execute(['table' => $table, 'index' => $index]);

            return (int) $stmt->fetchColumn() > 0;
        }

        return false;
    }

    private function dropIndexStatement(PDO $pdo, string $table, string $index): string
    {
        // MySQL/MariaDB require the owning table; SQLite uses the name only.
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? sprintf('DROP INDEX %s ON %s', $index, $table)
            : sprintf('DROP INDEX %s', $index);
    }
};
