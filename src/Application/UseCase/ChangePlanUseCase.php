<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use DomainException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\ChangePlanRequest;
use Ttpryg\SubscriptionEngine\Application\Service\InvoiceService;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPlanChangedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Service\ProrationCalculator;

final class ChangePlanUseCase
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceService $invoiceService,
        private readonly ProrationCalculator $prorationCalculator,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(ChangePlanRequest $request): Subscription
    {
        $plan = $this->planRepository->findById($request->newPlanId)
            ?? throw PlanNotFoundException::withId($request->newPlanId);

        if (! $plan->isActive) {
            throw new DomainException(sprintf('Plan "%s" is not accepting new subscriptions', $plan->id));
        }

        $subscription = $this->subscriptionRepository->findById($request->subscriptionId)
            ?? throw SubscriptionNotFoundException::withId($request->subscriptionId);

        $fromPlanId = $subscription->planId;
        $now = $this->clock->now();

        if (! $request->immediate) {
            $subscription->schedulePlanChange($plan->id, $now);
            $this->subscriptionRepository->save($subscription);
            $this->eventDispatcher?->dispatch(new SubscriptionPlanChangedEvent($subscription, $fromPlanId, $plan->id));

            return $subscription;
        }

        $oldPlan = $this->planRepository->findById($fromPlanId)
            ?? throw PlanNotFoundException::withId($fromPlanId);

        $subscription->applyPlan($plan->id, $now);
        $this->subscriptionRepository->save($subscription);

        if ($subscription->status === SubscriptionStatus::ACTIVE) {
            $amount = $this->prorationCalculator->calculate(
                $oldPlan,
                $plan,
                $subscription->currentPeriod(),
                $now
            );

            $this->invoiceService->issueProrated(
                $subscription,
                $plan,
                $amount,
                $now,
                $subscription->currentPeriodEnd
            );
        }

        $this->eventDispatcher?->dispatch(new SubscriptionPlanChangedEvent($subscription, $fromPlanId, $plan->id));

        return $subscription;
    }
}
