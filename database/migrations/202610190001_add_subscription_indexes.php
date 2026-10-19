<?php

declare(strict_types=1);

use Ttpryg\SubscriptionEngine\Infrastructure\Database\Migration;

/**
 * Add performance indexes used by the subscription engine repository adapters.
 *
 * Fresh installs get these indexes from `database/schema.sql`; this migration
 * upgrades databases created before the indexes existed.
 */
return new class extends Migration
{
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subs_user_store ON subscriptions(user_id, store_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subs_renewal ON subscriptions(status, current_period_end)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_subscription ON subscription_invoices(subscription_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_overdue ON subscription_invoices(status, due_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_plans_active ON subscription_plans(is_active, store_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS idx_subs_user_store');
        $pdo->exec('DROP INDEX IF EXISTS idx_subs_renewal');
        $pdo->exec('DROP INDEX IF EXISTS idx_inv_subscription');
        $pdo->exec('DROP INDEX IF EXISTS idx_inv_overdue');
        $pdo->exec('DROP INDEX IF EXISTS idx_plans_active');
    }

    public function previouslyRecordedAs(): array
    {
        return ['001_add_subscription_indexes'];
    }
};
