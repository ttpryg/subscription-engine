<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Closure;
use DomainException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\SubscribeRequest;
use Ttpryg\SubscriptionEngine\Application\Service\InvoiceService;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionCreatedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\ValueObject\BillingPeriod;

final class SubscribeUseCase
{
    private readonly ?Closure $transactionManager;

    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceService $invoiceService,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?callable $transactionManager = null
    ) {
        $this->transactionManager = $transactionManager !== null ? Closure::fromCallable($transactionManager) : null;
    }

    public function execute(SubscribeRequest $request): Subscription
    {
        $operation = fn (): Subscription => $this->doExecute($request);

        return $this->transactionManager !== null
            ? ($this->transactionManager)($operation)
            : $operation();
    }

    private function doExecute(SubscribeRequest $request): Subscription
    {
        $plan = $this->planRepository->findById($request->planId, lock: true)
            ?? throw PlanNotFoundException::withId($request->planId);

        if (! $plan->isActive) {
            throw new DomainException(sprintf('Plan "%s" is not accepting new subscriptions', $plan->id));
        }

        $now = $this->clock->now();
        $onTrial = $plan->hasTrial() && ! $request->skipTrial;
        $period = $onTrial
            ? BillingPeriod::trial($now, $plan->trialDays)
            : BillingPeriod::starting($now, $plan->interval, $plan->intervalCount);

        $subscription = new Subscription(
            id: $request->id,
            userId: $request->userId,
            planId: $plan->id,
            status: $onTrial ? SubscriptionStatus::TRIALING : SubscriptionStatus::ACTIVE,
            currentPeriodStart: $period->start,
            currentPeriodEnd: $period->end,
            storeId: $request->storeId ?? $plan->storeId,
            trialEndsAt: $onTrial ? $period->end : null,
            createdAt: $now
        );

        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionCreatedEvent($subscription));

        if (! $onTrial) {
            $this->invoiceService->issueForPeriod($subscription, $plan, $period);
        }

        return $subscription;
    }
}
