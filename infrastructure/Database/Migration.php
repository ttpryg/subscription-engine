<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Infrastructure\Database;

use PDO;
use RuntimeException;

/**
 * Base class for PHP-based schema migrations.
 *
 * Migration files live in `database/migrations/` and are named
 * `YYYYMMDDHHMM_description.php`. Each file must return an instance of this
 * class, for example:
 *
 *     return new class extends Migration {
 *         public function up(PDO $pdo): void { ... }
 *         public function down(PDO $pdo): void { ... }
 *     };
 */
abstract class Migration
{
    /**
     * Apply the schema change.
     */
    abstract public function up(PDO $pdo): void;

    /**
     * Revert the schema change.
     *
     * Migrations that cannot be reverted safely (for example, migrations that
     * destroy data) should keep this default implementation so `rollback()`
     * fails loudly instead of pretending the change was undone.
     */
    public function down(PDO $pdo): void
    {
        throw new RuntimeException(sprintf('%s does not support rollback.', static::class));
    }

    /**
     * Names this migration was recorded under before it was renamed.
     *
     * @return array<int, string>
     */
    public function previouslyRecordedAs(): array
    {
        return [];
    }
}
