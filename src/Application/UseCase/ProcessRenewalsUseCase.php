<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Closure;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\Service\InvoiceService;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionExpiredEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPastDueEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionRenewedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\PlanRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\ValueObject\BillingPeriod;

final class ProcessRenewalsUseCase
{
    private readonly ?Closure $transactionManager;

    /**
     * @param  (callable(callable(): mixed): mixed)|null  $transactionManager
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly InvoiceService $invoiceService,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?callable $transactionManager = null
    ) {
        $this->transactionManager = $transactionManager !== null ? Closure::fromCallable($transactionManager) : null;
    }

    /**
     * @return array{past_due: int, renewed: int, expired: int}
     */
    public function execute(): array
    {
        if ($this->transactionManager instanceof \Closure) {
            /** @var array{past_due: int, renewed: int, expired: int} */
            return ($this->transactionManager)(fn (): array => $this->doExecute());
        }

        return $this->doExecute();
    }

    /**
     * @return array{past_due: int, renewed: int, expired: int}
     */
    private function doExecute(): array
    {
        $now = $this->clock->now();
        $summary = ['past_due' => 0, 'renewed' => 0, 'expired' => 0];

        // 1. Overdue invoices push active subscriptions into PAST_DUE.
        foreach ($this->invoiceRepository->findOverdue($now) as $invoice) {
            $subscription = $this->subscriptionRepository->findById($invoice->subscriptionId);

            if ($subscription?->status === SubscriptionStatus::ACTIVE) {
                $subscription->markPastDue($now);
                $this->subscriptionRepository->save($subscription);
                $this->eventDispatcher?->dispatch(new SubscriptionPastDueEvent($subscription, $invoice));
                $summary['past_due']++;
            }
        }

        // 2. Subscriptions whose period ended: expire or renew.
        foreach ($this->subscriptionRepository->findDueForRenewal($now) as $subscription) {
            if ($subscription->cancelAtPeriodEnd || $subscription->status === SubscriptionStatus::PAST_DUE) {
                $subscription->expire($now);
                $this->invoiceService->voidOutstanding($subscription->id);
                $this->subscriptionRepository->save($subscription);
                $this->eventDispatcher?->dispatch(new SubscriptionExpiredEvent($subscription));
                $summary['expired']++;

                continue;
            }

            $planId = $subscription->pendingPlanId ?? $subscription->planId;
            $plan = $this->planRepository->findById($planId)
                ?? throw PlanNotFoundException::withId($planId);

            $period = BillingPeriod::starting($subscription->currentPeriodEnd, $plan->interval, $plan->intervalCount);

            $subscription->renew($period, $now);
            $this->subscriptionRepository->save($subscription);

            $invoice = $this->invoiceService->issueForPeriod($subscription, $plan, $period);
            $this->eventDispatcher?->dispatch(new SubscriptionRenewedEvent($subscription, $invoice));
            $summary['renewed']++;
        }

        return $summary;
    }
}
