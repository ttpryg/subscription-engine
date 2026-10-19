<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;
use Ttpryg\SubscriptionEngine\Domain\Event\PlanCreatedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;

class PlanService
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function createPlan(
        string $id,
        string $name,
        float $price,
        BillingInterval $interval,
        int $intervalCount = 1,
        string $currency = 'IDR',
        int $trialDays = 0,
        ?string $description = null,
        ?string $storeId = null
    ): Plan {
        $plan = new Plan(
            id: $id,
            name: $name,
            price: $price,
            interval: $interval,
            intervalCount: $intervalCount,
            currency: $currency,
            trialDays: $trialDays,
            description: $description,
            storeId: $storeId
        );

        $this->planRepository->save($plan);
        $this->eventDispatcher?->dispatch(new PlanCreatedEvent($plan));

        return $plan;
    }

    public function getPlan(string $id): Plan
    {
        return $this->planRepository->findById($id) ?? throw PlanNotFoundException::withId($id);
    }

    /** @return Plan[] */
    public function listActivePlans(?string $storeId = null): array
    {
        return $this->planRepository->findActive($storeId);
    }

    /** Price changes apply to the next invoice issued for every subscriber of the plan. */
    public function changePrice(string $id, float $price): Plan
    {
        $plan = $this->getPlan($id);
        $plan->changePrice($price);
        $this->planRepository->save($plan);

        return $plan;
    }

    /** Deactivated plans cannot accept new subscribers; existing subscribers keep renewing. */
    public function deactivatePlan(string $id): Plan
    {
        $plan = $this->getPlan($id);
        $plan->deactivate();
        $this->planRepository->save($plan);

        return $plan;
    }

    public function activatePlan(string $id): Plan
    {
        $plan = $this->getPlan($id);
        $plan->activate();
        $this->planRepository->save($plan);

        return $plan;
    }
}
