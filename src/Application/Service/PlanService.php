<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\CreatePlanRequest;
use Ttpryg\SubscriptionEngine\Application\UseCase\CreatePlanUseCase;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Exception\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;

class PlanService
{
    private readonly CreatePlanUseCase $createPlanUseCase;

    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->createPlanUseCase = new CreatePlanUseCase($planRepository, $eventDispatcher);
    }

    public function createPlan(CreatePlanRequest $request): Plan
    {
        return $this->createPlanUseCase->execute($request);
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
