<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ttpryg\SubscriptionEngine\Infrastructure\Database\MigrationRunner;

class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;

    private string $directory;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->directory = sys_get_temp_dir().'/subscription_engine_migrations_'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function test_migrate_executes_migrations_in_alphabetical_order(): void
    {
        $this->addMigration('202601010000_first');
        $this->addMigration('202601010001_second');

        $executed = (new MigrationRunner($this->pdo, $this->directory))->migrate();

        $this->assertSame(['202601010000_first', '202601010001_second'], $executed);
    }

    public function test_migrate_is_idempotent(): void
    {
        $this->addMigration('202601010000_first');
        $runner = new MigrationRunner($this->pdo, $this->directory);

        $runner->migrate();

        $this->assertSame([], $runner->migrate());
        $this->assertSame(['202601010000_first'], $runner->appliedMigrations());
    }

    public function test_rollback_reverts_migrations_in_reverse_order(): void
    {
        $this->addMigration('202601010000_first');
        $this->addMigration('202601010001_second');
        $runner = new MigrationRunner($this->pdo, $this->directory);

        $runner->migrate();
        $rolledBack = $runner->rollback(2);

        $this->assertSame(['202601010001_second', '202601010000_first'], $rolledBack);
        $this->assertSame([], $runner->appliedMigrations());
    }

    public function test_rollback_without_applied_migrations_returns_empty(): void
    {
        $this->addMigration('202601010000_first');

        $this->assertSame([], (new MigrationRunner($this->pdo, $this->directory))->rollback());
    }

    public function test_missing_migrations_directory_is_treated_as_empty(): void
    {
        $runner = new MigrationRunner($this->pdo, $this->directory.'/does-not-exist');

        $this->assertSame([], $runner->migrate());
    }

    public function test_invalid_migration_file_throws(): void
    {
        file_put_contents($this->directory.'/202601010000_invalid.php', '<?php return new stdClass;');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return an instance of');

        (new MigrationRunner($this->pdo, $this->directory))->migrate();
    }

    private function addMigration(string $name, string $up = '// no-op', string $down = '// no-op'): void
    {
        $template = <<<'PHP'
<?php

declare(strict_types=1);

use Ttpryg\SubscriptionEngine\Infrastructure\Database\Migration;

return new class extends Migration
{
    public function up(PDO $pdo): void
    {
        %s
    }

    public function down(PDO $pdo): void
    {
        %s
    }
};
PHP;

        file_put_contents($this->directory.'/'.$name.'.php', sprintf($template, $up, $down));
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
