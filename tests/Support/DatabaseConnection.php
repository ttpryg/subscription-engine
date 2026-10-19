<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Support;

use PDO;

final class DatabaseConnection
{
    /**
     * Connect to a MySQL/MariaDB server when SUBSCRIPTION_ENGINE_DB_DSN is set,
     * otherwise use an isolated in-memory SQLite database.
     */
    public static function fromEnvironment(): PDO
    {
        $dsn = getenv('SUBSCRIPTION_ENGINE_DB_DSN');

        if (is_string($dsn) && $dsn !== '') {
            return new PDO(
                $dsn,
                (string) getenv('SUBSCRIPTION_ENGINE_DB_USER'),
                (string) getenv('SUBSCRIPTION_ENGINE_DB_PASSWORD')
            );
        }

        return new PDO('sqlite::memory:');
    }

    public static function isMysql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * Drop the tables owned by this module so a test can start from a clean
     * schema. No-op for in-memory SQLite, which starts empty per connection.
     */
    public static function dropEngineTables(PDO $pdo): void
    {
        if (! self::isMysql($pdo)) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec(
            'DROP TABLE IF EXISTS subscription_invoices, subscriptions, subscription_plans, subscription_engine_migrations'
        );
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
