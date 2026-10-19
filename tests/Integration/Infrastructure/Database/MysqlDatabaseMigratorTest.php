<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Integration\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Infrastructure\Database\DatabaseMigrator;
use Ttpryg\SubscriptionEngine\Infrastructure\Database\MigrationRunner;
use Ttpryg\SubscriptionEngine\Tests\Support\DatabaseConnection;

class MysqlDatabaseMigratorTest extends TestCase
{
    private const MIGRATION = '202610190001_add_subscription_indexes';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseConnection::fromEnvironment();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (! DatabaseConnection::isMysql($this->pdo)) {
            $this->markTestSkipped('Set SUBSCRIPTION_ENGINE_DB_DSN to run MySQL/MariaDB integration tests.');
        }

        DatabaseConnection::dropEngineTables($this->pdo);
    }

    public function test_fresh_install_creates_tables_and_indexes(): void
    {
        (new DatabaseMigrator($this->pdo))->migrate();

        $this->assertTrue($this->tableExists('subscription_plans'));
        $this->assertTrue($this->tableExists('subscriptions'));
        $this->assertTrue($this->tableExists('subscription_invoices'));
        $this->assertTrue($this->indexExists('subscriptions', 'idx_subs_user_store'));
        $this->assertTrue($this->indexExists('subscription_invoices', 'idx_inv_overdue'));
        $this->assertContains(self::MIGRATION, (new MigrationRunner($this->pdo))->appliedMigrations());
    }

    public function test_legacy_upgrade_and_rollback_manage_indexes(): void
    {
        (new DatabaseMigrator($this->pdo))->migrate();

        // Simulate a legacy database: indexes missing and the migration untracked.
        $this->pdo->exec('DROP INDEX idx_subs_user_store ON subscriptions');
        $this->pdo->exec('DELETE FROM subscription_engine_migrations');
        $this->assertFalse($this->indexExists('subscriptions', 'idx_subs_user_store'));

        $runner = new MigrationRunner($this->pdo);
        $this->assertContains(self::MIGRATION, $runner->migrate());
        $this->assertTrue($this->indexExists('subscriptions', 'idx_subs_user_store'));

        $this->assertSame([self::MIGRATION], $runner->rollback());
        $this->assertFalse($this->indexExists('subscriptions', 'idx_subs_user_store'));
        // InnoDB keeps the index backing the foreign key; rollback is still safe.
        $this->assertTrue($this->indexExists('subscription_invoices', 'idx_inv_subscription'));
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $stmt->execute(['table' => $table, 'index' => $index]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
