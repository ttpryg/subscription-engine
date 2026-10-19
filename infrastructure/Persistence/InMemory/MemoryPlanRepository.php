<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Infrastructure\Persistence\InMemory;

use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;

class MemoryPlanRepository implements PlanRepositoryInterface
{
    /** @var array<string, Plan> */
    private array $plans = [];

    public function save(Plan $plan): void
    {
        $this->plans[$plan->id] = $plan;
    }

    public function findById(string $id, bool $lock = false): ?Plan
    {
        return $this->plans[$id] ?? null;
    }

    public function findActive(?string $storeId = null): array
    {
        return array_values(array_filter(
            $this->plans,
            static fn (Plan $plan): bool => $plan->isActive && ($storeId === null || $plan->storeId === $storeId)
        ));
    }
}
