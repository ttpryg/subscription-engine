<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\CreatePlanRequest;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Event\PlanCreatedEvent;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;

final class CreatePlanUseCase
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(CreatePlanRequest $request): Plan
    {
        $plan = new Plan(
            id: $request->id,
            name: $request->name,
            price: $request->price,
            interval: $request->interval,
            intervalCount: $request->intervalCount,
            currency: $request->currency,
            trialDays: $request->trialDays,
            description: $request->description,
            storeId: $request->storeId
        );

        $this->planRepository->save($plan);
        $this->eventDispatcher?->dispatch(new PlanCreatedEvent($plan));

        return $plan;
    }
}
