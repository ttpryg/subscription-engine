<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Repositories;

use PDO;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;
use Ttpryg\SubscriptionEngine\Repositories\Concerns\InteractsWithPdo;

class PdoPlanRepository implements PlanRepositoryInterface
{
    use InteractsWithPdo;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'subscription_plans'
    ) {}

    public function save(Plan $plan): void
    {
        $this->upsert($this->pdo, $this->table, [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->interval->value,
            'interval_count' => $plan->intervalCount,
            'trial_days' => $plan->trialDays,
            'store_id' => $plan->storeId,
            'is_active' => $plan->isActive ? 1 : 0,
            'created_at' => self::toDb($plan->createdAt),
            'updated_at' => self::toDb($plan->updatedAt),
        ]);
    }

    public function findById(string $id): ?Plan
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE id = :id', $this->table));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findActive(?string $storeId = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE is_active = 1', $this->table);
        $params = [];

        if ($storeId !== null) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $stmt = $this->pdo->prepare($sql.' ORDER BY price ASC');
        $stmt->execute($params);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Plan
    {
        return new Plan(
            id: (string) $row['id'],
            name: (string) $row['name'],
            price: (float) $row['price'],
            interval: BillingInterval::from((string) $row['billing_interval']),
            intervalCount: (int) $row['interval_count'],
            currency: (string) $row['currency'],
            trialDays: (int) $row['trial_days'],
            description: $row['description'] ?? null,
            storeId: $row['store_id'] ?? null,
            isActive: (bool) $row['is_active'],
            createdAt: self::fromDb($row['created_at']),
            updatedAt: self::fromDb($row['updated_at'])
        );
    }
}
