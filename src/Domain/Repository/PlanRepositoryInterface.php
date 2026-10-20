<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Repository;

use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;

interface PlanRepositoryInterface
{
    public function save(Plan $plan): void;

    /**
     * @param  bool  $lock  If true, the PDO implementation issues `SELECT ... FOR UPDATE`
     *                      (must be called inside a transaction). Ignored by in-memory adapters.
     */
    public function findById(string $id, bool $lock = false): ?Plan;

    /** @return Plan[] */
    public function findActive(?string $storeId = null): array;
}
