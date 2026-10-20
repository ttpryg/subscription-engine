<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Infrastructure\Database\PdoTransactionManager;

class PdoTransactionManagerTest extends TestCase
{
    private function createPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE test_items (id TEXT PRIMARY KEY, value TEXT)');

        return $pdo;
    }

    public function test_execute_commits_when_operation_succeeds(): void
    {
        $pdo = $this->createPdo();
        $manager = new PdoTransactionManager($pdo);

        $result = $manager->execute(function () use ($pdo): string {
            $pdo->prepare('INSERT INTO test_items (id, value) VALUES (:id, :value)')
                ->execute(['id' => 'item_1', 'value' => 'hello']);

            return 'done';
        });

        $this->assertSame('done', $result);

        $stmt = $pdo->prepare('SELECT value FROM test_items WHERE id = :id');
        $stmt->execute(['id' => 'item_1']);

        $this->assertSame('hello', $stmt->fetchColumn());
    }

    public function test_execute_rolls_back_when_operation_throws(): void
    {
        $pdo = $this->createPdo();
        $manager = new PdoTransactionManager($pdo);

        try {
            $manager->execute(function () use ($pdo): void {
                $pdo->prepare('INSERT INTO test_items (id, value) VALUES (:id, :value)')
                    ->execute(['id' => 'item_1', 'value' => 'hello']);

                throw new \RuntimeException('boom');
            });

            /** @phpstan-ignore-next-line */
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM test_items WHERE id = :id');
        $stmt->execute(['id' => 'item_1']);

        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
