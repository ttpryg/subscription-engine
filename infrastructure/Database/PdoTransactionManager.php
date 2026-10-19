<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Infrastructure\Database;

use PDO;
use Ttpryg\SubscriptionEngine\Domain\Service\TransactionManagerInterface;

/**
 * Transaction manager backed by a PDO connection.
 *
 * Requires PHP 8.1+ (PDO::transaction()).
 */
final class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function execute(callable $operation): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
