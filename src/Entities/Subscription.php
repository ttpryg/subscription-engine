<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Entities;

use DateTimeImmutable;
use Ttpryg\SubscriptionEngine\Enums\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Exceptions\InvalidSubscriptionStateException;
use Ttpryg\SubscriptionEngine\ValueObjects\BillingPeriod;

class Subscription
{
    public readonly DateTimeImmutable $createdAt;

    public DateTimeImmutable $updatedAt;

    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public string $planId,
        public SubscriptionStatus $status,
        public DateTimeImmutable $currentPeriodStart,
        public DateTimeImmutable $currentPeriodEnd,
        public readonly ?string $storeId = null,
        public ?DateTimeImmutable $trialEndsAt = null,
        public ?string $pendingPlanId = null,
        public bool $cancelAtPeriodEnd = false,
        public ?DateTimeImmutable $canceledAt = null,
        public ?DateTimeImmutable $pausedAt = null,
        public ?DateTimeImmutable $endedAt = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? $this->createdAt;
    }

    public function currentPeriod(): BillingPeriod
    {
        return new BillingPeriod($this->currentPeriodStart, $this->currentPeriodEnd);
    }

    public function hasAccess(DateTimeImmutable $now): bool
    {
        return $this->status->grantsAccess() && $now < $this->currentPeriodEnd;
    }

    public function isOnTrial(DateTimeImmutable $now): bool
    {
        return $this->status === SubscriptionStatus::TRIALING
            && $this->trialEndsAt instanceof DateTimeImmutable
            && $now < $this->trialEndsAt;
    }

    /** Moves the subscription into a new paid billing period, applying any scheduled plan change. */
    public function renew(BillingPeriod $period, DateTimeImmutable $now): void
    {
        $this->assertStatus('renew', SubscriptionStatus::TRIALING, SubscriptionStatus::ACTIVE);

        if ($this->pendingPlanId !== null) {
            $this->planId = $this->pendingPlanId;
            $this->pendingPlanId = null;
        }

        $this->currentPeriodStart = $period->start;
        $this->currentPeriodEnd = $period->end;
        $this->status = SubscriptionStatus::ACTIVE;
        $this->touch($now);
    }

    public function markPastDue(DateTimeImmutable $now): void
    {
        $this->assertStatus('mark past due', SubscriptionStatus::ACTIVE);
        $this->status = SubscriptionStatus::PAST_DUE;
        $this->touch($now);
    }

    public function reactivate(DateTimeImmutable $now): void
    {
        $this->assertStatus('reactivate', SubscriptionStatus::PAST_DUE);
        $this->status = SubscriptionStatus::ACTIVE;
        $this->touch($now);
    }

    public function pause(DateTimeImmutable $now): void
    {
        $this->assertStatus('pause', SubscriptionStatus::ACTIVE, SubscriptionStatus::TRIALING);
        $this->status = SubscriptionStatus::PAUSED;
        $this->pausedAt = $now;
        $this->touch($now);
    }

    /** Resumes and extends the current period by the time spent paused. */
    public function resume(DateTimeImmutable $now): void
    {
        $this->assertStatus('resume', SubscriptionStatus::PAUSED);

        $pausedSeconds = max(0, $now->getTimestamp() - ($this->pausedAt?->getTimestamp() ?? $now->getTimestamp()));
        $this->currentPeriodEnd = $this->currentPeriodEnd->modify(sprintf('+%d seconds', $pausedSeconds));

        if ($this->trialEndsAt instanceof DateTimeImmutable) {
            $this->trialEndsAt = $this->trialEndsAt->modify(sprintf('+%d seconds', $pausedSeconds));
        }

        $this->status = $this->trialEndsAt instanceof DateTimeImmutable && $now < $this->trialEndsAt
            ? SubscriptionStatus::TRIALING
            : SubscriptionStatus::ACTIVE;
        $this->pausedAt = null;
        $this->touch($now);
    }

    public function cancel(DateTimeImmutable $now, bool $atPeriodEnd = true): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidSubscriptionStateException::cannot('cancel', $this->status);
        }

        $this->canceledAt = $now;

        if ($atPeriodEnd && $this->status !== SubscriptionStatus::PAUSED) {
            $this->cancelAtPeriodEnd = true;
        } else {
            $this->status = SubscriptionStatus::CANCELED;
            $this->cancelAtPeriodEnd = false;
            $this->endedAt = $now;
        }

        $this->touch($now);
    }

    /** Undo a scheduled cancel-at-period-end. */
    public function undoCancellation(DateTimeImmutable $now): void
    {
        if (! $this->cancelAtPeriodEnd || $this->status->isTerminal()) {
            throw InvalidSubscriptionStateException::cannot('undo cancellation of', $this->status);
        }

        $this->cancelAtPeriodEnd = false;
        $this->canceledAt = null;
        $this->touch($now);
    }

    public function expire(DateTimeImmutable $now): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidSubscriptionStateException::cannot('expire', $this->status);
        }

        $this->status = SubscriptionStatus::EXPIRED;
        $this->endedAt = $now;
        $this->touch($now);
    }

    public function schedulePlanChange(string $planId, DateTimeImmutable $now): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidSubscriptionStateException::cannot('change plan of', $this->status);
        }

        $this->pendingPlanId = $planId === $this->planId ? null : $planId;
        $this->touch($now);
    }

    public function applyPlan(string $planId, DateTimeImmutable $now): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidSubscriptionStateException::cannot('change plan of', $this->status);
        }

        $this->planId = $planId;
        $this->pendingPlanId = null;
        $this->touch($now);
    }

    private function assertStatus(string $action, SubscriptionStatus ...$allowed): void
    {
        if (! in_array($this->status, $allowed, true)) {
            throw InvalidSubscriptionStateException::cannot($action, $this->status);
        }
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
