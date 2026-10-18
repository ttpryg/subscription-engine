<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Database\DatabaseMigrator;
use Ttpryg\SubscriptionEngine\Database\MigrationRunner;

class DatabaseMigratorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function test_fresh_install_creates_schema_and_baselines_migrations(): void
    {
        (new DatabaseMigrator($this->pdo))->migrate();

        $this->assertTrue($this->tableExists('subscription_plans'));
        $this->assertTrue($this->tableExists('subscriptions'));
        $this->assertTrue($this->tableExists('subscription_invoices'));
        $this->assertTrue($this->indexExists('idx_subs_user_store'));
        $this->assertTrue($this->indexExists('idx_inv_overdue'));

        $applied = (new MigrationRunner($this->pdo))->appliedMigrations();
        $this->assertContains('001_add_subscription_indexes', $applied);
    }

    public function test_migration_runner_adds_indexes_to_legacy_database(): void
    {
        $this->createLegacyTables();
        $this->assertFalse($this->indexExists('idx_subs_user_store'));

        $executed = (new MigrationRunner($this->pdo))->migrate();

        $this->assertContains('001_add_subscription_indexes', $executed);
        $this->assertTrue($this->indexExists('idx_subs_user_store'));
        $this->assertTrue($this->indexExists('idx_subs_renewal'));
        $this->assertTrue($this->indexExists('idx_inv_subscription'));
        $this->assertTrue($this->indexExists('idx_plans_active'));
    }

    public function test_migration_runner_is_idempotent(): void
    {
        $this->createLegacyTables();
        $runner = new MigrationRunner($this->pdo);

        $this->assertContains('001_add_subscription_indexes', $runner->migrate());
        $this->assertSame([], $runner->migrate());
    }

    public function test_database_migrator_is_idempotent(): void
    {
        $migrator = new DatabaseMigrator($this->pdo);

        $migrator->migrate();
        $migrator->migrate();

        $this->assertSame(1, count((new MigrationRunner($this->pdo))->appliedMigrations()));
    }

    public function test_existing_database_gets_upgraded_without_losing_data(): void
    {
        $this->createLegacyTables();
        $this->pdo->exec("INSERT INTO subscriptions (id, user_id, status) VALUES ('s1', 'u1', 'active')");

        (new DatabaseMigrator($this->pdo))->migrate();

        $this->assertTrue($this->indexExists('idx_subs_user_store'));
        $this->assertSame(1, $this->countRows('subscriptions'));
    }

    private function createLegacyTables(): void
    {
        $this->pdo->exec(
            'CREATE TABLE subscription_plans (
                id VARCHAR(36) PRIMARY KEY,
                is_active TINYINT(1) DEFAULT 1,
                store_id VARCHAR(36) NULL
            );
            CREATE TABLE subscriptions (
                id VARCHAR(36) PRIMARY KEY,
                user_id VARCHAR(36) NULL,
                status VARCHAR(20) NULL,
                store_id VARCHAR(36) NULL,
                current_period_end DATETIME NULL
            );
            CREATE TABLE subscription_invoices (
                id VARCHAR(36) PRIMARY KEY,
                subscription_id VARCHAR(36) NULL,
                status VARCHAR(20) NULL,
                due_at DATETIME NULL
            );'
        );
    }

    private function tableExists(string $table): bool
    {
        return $this->sqliteObjectExists('table', $table);
    }

    private function indexExists(string $index): bool
    {
        return $this->sqliteObjectExists('index', $index);
    }

    private function sqliteObjectExists(string $type, string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE type = :type AND name = :name');
        $stmt->execute(['type' => $type, 'name' => $name]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
