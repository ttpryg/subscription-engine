<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Services;

use Closure;
use DomainException;
use Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Clock\SystemClock;
use Ttpryg\SubscriptionEngine\Contracts\ClockInterface;
use Ttpryg\SubscriptionEngine\Contracts\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Contracts\PlanRepositoryInterface;
use Ttpryg\SubscriptionEngine\Contracts\SubscriptionRepositoryInterface;
use Ttpryg\SubscriptionEngine\Entities\Plan;
use Ttpryg\SubscriptionEngine\Entities\Subscription;
use Ttpryg\SubscriptionEngine\Entities\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Enums\InvoiceStatus;
use Ttpryg\SubscriptionEngine\Enums\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Events\SubscriptionCanceledEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionCreatedEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionExpiredEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionPastDueEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionPausedEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionPaymentFailedEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionPaymentSucceededEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionPlanChangedEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionRenewalDueEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionRenewedEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionResumedEvent;
use Ttpryg\SubscriptionEngine\Exceptions\InvoiceNotFoundException;
use Ttpryg\SubscriptionEngine\Exceptions\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Exceptions\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\ValueObjects\BillingPeriod;

/**
 * Lifecycle:
 *   subscribe ──► TRIALING ──(trial ends)──► ACTIVE ◄──(payment)── PAST_DUE
 *                    │                         │  (invoice overdue) ──►┘   │
 *                    └──────── pause/resume ───┤                          │
 *                                              ▼                          ▼
 *                                  CANCELED / EXPIRED ◄──(period ends unpaid)
 *
 * Payment collection is intentionally decoupled: listen to SubscriptionRenewalDueEvent,
 * charge the invoice with your gateway, then call recordPayment() or recordPaymentFailure().
 */
class SubscriptionService
{
    private readonly ClockInterface $clock;

    private readonly Closure $idGenerator;

    /** @var (Closure(Closure(): mixed): mixed)|null */
    private readonly ?Closure $transactionManager;

    /**
     * @param  int  $graceDays  Days after an invoice is issued before the subscription becomes PAST_DUE.
     * @param  (callable(): string)|null  $idGenerator  Generates invoice IDs (defaults to UUID v4).
     * @param  (callable(callable(): mixed): mixed)|null  $transactionManager  Wraps execution in a DB transaction.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        ?ClockInterface $clock = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly int $graceDays = 3,
        ?callable $idGenerator = null,
        ?callable $transactionManager = null
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->idGenerator = $idGenerator !== null ? Closure::fromCallable($idGenerator) : $this->uuid4(...);
        $this->transactionManager = $transactionManager !== null ? Closure::fromCallable($transactionManager) : null;
    }

    public function subscribe(
        string $id,
        string $userId,
        string $planId,
        ?string $storeId = null,
        bool $skipTrial = false
    ): Subscription {
        $plan = $this->requirePlan($planId);

        if (! $plan->isActive) {
            throw new DomainException(sprintf('Plan "%s" is not accepting new subscriptions', $planId));
        }

        $now = $this->clock->now();
        $onTrial = $plan->hasTrial() && ! $skipTrial;
        $period = $onTrial
            ? BillingPeriod::trial($now, $plan->trialDays)
            : BillingPeriod::starting($now, $plan->interval, $plan->intervalCount);

        $subscription = new Subscription(
            id: $id,
            userId: $userId,
            planId: $plan->id,
            status: $onTrial ? SubscriptionStatus::TRIALING : SubscriptionStatus::ACTIVE,
            currentPeriodStart: $period->start,
            currentPeriodEnd: $period->end,
            storeId: $storeId ?? $plan->storeId,
            trialEndsAt: $onTrial ? $period->end : null,
            createdAt: $now
        );

        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionCreatedEvent($subscription));

        if (! $onTrial) {
            $this->issueInvoice($subscription, $plan, $period);
        }

        return $subscription;
    }

    public function getSubscription(string $id): Subscription
    {
        return $this->subscriptionRepository->findById($id) ?? throw SubscriptionNotFoundException::withId($id);
    }

    /** @return Subscription[] */
    public function getUserSubscriptions(string $userId, ?string $storeId = null): array
    {
        return $this->subscriptionRepository->findByUser($userId, $storeId);
    }

    /** Access check, e.g. for feature gating / middleware. */
    public function hasAccess(string $userId, ?string $planId = null, ?string $storeId = null): bool
    {
        $now = $this->clock->now();

        foreach ($this->subscriptionRepository->findByUser($userId, $storeId) as $subscription) {
            if (($planId === null || $subscription->planId === $planId) && $subscription->hasAccess($now)) {
                return true;
            }
        }

        return false;
    }

    /** @return SubscriptionInvoice[] */
    public function getInvoices(string $subscriptionId): array
    {
        return $this->invoiceRepository->findBySubscription($subscriptionId);
    }

    public function recordPayment(string $invoiceId, ?string $paymentReference = null): SubscriptionInvoice
    {
        $invoice = $this->requireInvoice($invoiceId);
        $wasAlreadyPaid = ($invoice->status === InvoiceStatus::PAID);
        $now = $this->clock->now();

        $invoice->markPaid($now, $paymentReference);
        $this->invoiceRepository->save($invoice);

        $subscription = $this->getSubscription($invoice->subscriptionId);

        if ($subscription->status === SubscriptionStatus::PAST_DUE && ! $this->hasOutstandingInvoices($subscription->id)) {
            $subscription->reactivate($now);
            $this->subscriptionRepository->save($subscription);
        }

        if (! $wasAlreadyPaid) {
            $this->eventDispatcher?->dispatch(new SubscriptionPaymentSucceededEvent($subscription, $invoice));
        }

        return $invoice;
    }

    public function recordPaymentFailure(string $invoiceId, ?string $reason = null): SubscriptionInvoice
    {
        $invoice = $this->requireInvoice($invoiceId);
        $invoice->markFailed();
        $this->invoiceRepository->save($invoice);

        $subscription = $this->getSubscription($invoice->subscriptionId);
        $this->eventDispatcher?->dispatch(new SubscriptionPaymentFailedEvent($subscription, $invoice, $reason));

        return $invoice;
    }

    /**
     * Changes the subscription plan.
     *
     * @param  bool  $immediate  If false, takes effect at next renewal. If true, takes effect immediately with prorated invoice.
     */
    public function changePlan(string $subscriptionId, string $newPlanId, bool $immediate = false): Subscription
    {
        $plan = $this->requirePlan($newPlanId);

        if (! $plan->isActive) {
            throw new DomainException(sprintf('Plan "%s" is not accepting new subscriptions', $newPlanId));
        }

        $subscription = $this->getSubscription($subscriptionId);
        $fromPlanId = $subscription->planId;
        $now = $this->clock->now();

        if (! $immediate) {
            $subscription->schedulePlanChange($plan->id, $now);
            $this->subscriptionRepository->save($subscription);
            $this->eventDispatcher?->dispatch(new SubscriptionPlanChangedEvent($subscription, $fromPlanId, $plan->id));

            return $subscription;
        }

        $oldPlan = $this->requirePlan($fromPlanId);
        $subscription->applyPlan($plan->id, $now);
        $this->subscriptionRepository->save($subscription);

        // Proration calculation: charge difference for remaining cycle if currently active
        if ($subscription->status === SubscriptionStatus::ACTIVE) {
            $currentStart = $subscription->currentPeriodStart->getTimestamp();
            $currentEnd = $subscription->currentPeriodEnd->getTimestamp();
            $nowTs = $now->getTimestamp();

            $totalSeconds = max(1, $currentEnd - $currentStart);
            $remainingSeconds = max(0, min($currentEnd - $nowTs, $totalSeconds));
            $remainingRatio = $remainingSeconds / $totalSeconds;

            $unusedCredit = $oldPlan->price * $remainingRatio;
            $newPlanCost = $plan->price * $remainingRatio;
            $proratedAmount = round(max(0.0, $newPlanCost - $unusedCredit), 2);

            if ($proratedAmount > 0) {
                $invoice = new SubscriptionInvoice(
                    id: ($this->idGenerator)(),
                    subscriptionId: $subscription->id,
                    userId: $subscription->userId,
                    planId: $plan->id,
                    amount: $proratedAmount,
                    currency: $plan->currency,
                    periodStart: $now,
                    periodEnd: $subscription->currentPeriodEnd,
                    dueAt: $now->modify(sprintf('+%d days', $this->graceDays)),
                    createdAt: $now
                );
                $this->invoiceRepository->save($invoice);
                $this->eventDispatcher?->dispatch(new SubscriptionRenewalDueEvent($subscription, $invoice));
            }
        }

        $this->eventDispatcher?->dispatch(new SubscriptionPlanChangedEvent($subscription, $fromPlanId, $plan->id));

        return $subscription;
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd = true): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);
        $subscription->cancel($this->clock->now(), $atPeriodEnd);

        if ($subscription->status === SubscriptionStatus::CANCELED) {
            $this->voidOutstandingInvoices($subscription->id);
        }

        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionCanceledEvent($subscription));

        return $subscription;
    }

    public function undoCancellation(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);
        $subscription->undoCancellation($this->clock->now());
        $this->subscriptionRepository->save($subscription);

        return $subscription;
    }

    public function pause(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);
        $subscription->pause($this->clock->now());
        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionPausedEvent($subscription));

        return $subscription;
    }

    public function resume(string $subscriptionId): Subscription
    {
        $subscription = $this->getSubscription($subscriptionId);
        $subscription->resume($this->clock->now());
        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionResumedEvent($subscription));

        return $subscription;
    }

    /**
     * Run periodically (e.g. cron every 5–15 minutes). Idempotent per moment in time.
     *
     * @return array{past_due: int, renewed: int, expired: int}
     */
    public function processRenewals(): array
    {
        if ($this->transactionManager instanceof \Closure) {
            /** @var array{past_due: int, renewed: int, expired: int} */
            return ($this->transactionManager)(fn (): array => $this->doProcessRenewals());
        }

        return $this->doProcessRenewals();
    }

    /** @return array{past_due: int, renewed: int, expired: int} */
    private function doProcessRenewals(): array
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
                $this->voidOutstandingInvoices($subscription->id);
                $this->subscriptionRepository->save($subscription);
                $this->eventDispatcher?->dispatch(new SubscriptionExpiredEvent($subscription));
                $summary['expired']++;

                continue;
            }

            $plan = $this->requirePlan($subscription->pendingPlanId ?? $subscription->planId);
            $period = BillingPeriod::starting($subscription->currentPeriodEnd, $plan->interval, $plan->intervalCount);

            $subscription->renew($period, $now);
            $this->subscriptionRepository->save($subscription);

            $invoice = $this->issueInvoice($subscription, $plan, $period);
            $this->eventDispatcher?->dispatch(new SubscriptionRenewedEvent($subscription, $invoice));
            $summary['renewed']++;
        }

        return $summary;
    }

    private function issueInvoice(Subscription $subscription, Plan $plan, BillingPeriod $period): SubscriptionInvoice
    {
        $now = $this->clock->now();

        $invoice = new SubscriptionInvoice(
            id: ($this->idGenerator)(),
            subscriptionId: $subscription->id,
            userId: $subscription->userId,
            planId: $plan->id,
            amount: $plan->price,
            currency: $plan->currency,
            periodStart: $period->start,
            periodEnd: $period->end,
            dueAt: $period->start->modify(sprintf('+%d days', $this->graceDays)),
            createdAt: $now
        );

        // Free plans are settled immediately; nothing to collect.
        if ($invoice->amount <= 0) {
            $invoice->markPaid($now, 'free');
        }

        $this->invoiceRepository->save($invoice);

        if ($invoice->isOutstanding()) {
            $this->eventDispatcher?->dispatch(new SubscriptionRenewalDueEvent($subscription, $invoice));
        }

        return $invoice;
    }

    private function hasOutstandingInvoices(string $subscriptionId): bool
    {
        foreach ($this->invoiceRepository->findBySubscription($subscriptionId) as $invoice) {
            if ($invoice->isOutstanding()) {
                return true;
            }
        }

        return false;
    }

    private function voidOutstandingInvoices(string $subscriptionId): void
    {
        foreach ($this->invoiceRepository->findBySubscription($subscriptionId) as $invoice) {
            if ($invoice->isOutstanding()) {
                $invoice->void();
                $this->invoiceRepository->save($invoice);
            }
        }
    }

    private function requirePlan(string $id): Plan
    {
        return $this->planRepository->findById($id) ?? throw PlanNotFoundException::withId($id);
    }

    private function requireInvoice(string $id): SubscriptionInvoice
    {
        return $this->invoiceRepository->findById($id) ?? throw InvoiceNotFoundException::withId($id);
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
