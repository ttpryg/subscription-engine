<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Entities;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Entities\Subscription;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;
use Ttpryg\SubscriptionEngine\Enums\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Exceptions\InvalidSubscriptionStateException;
use Ttpryg\SubscriptionEngine\ValueObjects\BillingPeriod;

class SubscriptionTest extends TestCase
{
    private function createSubscription(
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
        ?DateTimeImmutable $start = null,
        ?DateTimeImmutable $end = null
    ): Subscription {
        $start ??= new DateTimeImmutable('2026-01-01 00:00:00');
        $end ??= new DateTimeImmutable('2026-02-01 00:00:00');

        return new Subscription(
            id: 'sub_test',
            userId: 'user_1',
            planId: 'plan_standard',
            status: $status,
            currentPeriodStart: $start,
            currentPeriodEnd: $end
        );
    }

    public function test_has_access(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $end = new DateTimeImmutable('2026-02-01 00:00:00');
        $sub = $this->createSubscription(SubscriptionStatus::ACTIVE, $start, $end);

        $this->assertTrue($sub->hasAccess(new DateTimeImmutable('2026-01-15 00:00:00')));
        $this->assertFalse($sub->hasAccess(new DateTimeImmutable('2026-02-02 00:00:00')));

        // Past due retains access until period ends or expires
        $pastDue = $this->createSubscription(SubscriptionStatus::PAST_DUE, $start, $end);
        $this->assertTrue($pastDue->hasAccess(new DateTimeImmutable('2026-01-15 00:00:00')));

        // Canceled has no access
        $canceled = $this->createSubscription(SubscriptionStatus::CANCELED, $start, $end);
        $this->assertFalse($canceled->hasAccess(new DateTimeImmutable('2026-01-15 00:00:00')));
    }

    public function test_is_on_trial(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $end = new DateTimeImmutable('2026-01-15 00:00:00');
        $sub = new Subscription(
            id: 'sub_trial',
            userId: 'user_1',
            planId: 'plan_standard',
            status: SubscriptionStatus::TRIALING,
            currentPeriodStart: $start,
            currentPeriodEnd: $end,
            trialEndsAt: $end
        );

        $this->assertTrue($sub->isOnTrial(new DateTimeImmutable('2026-01-10 00:00:00')));
        $this->assertFalse($sub->isOnTrial(new DateTimeImmutable('2026-01-16 00:00:00')));
    }

    public function test_renew_updates_period_and_applies_pending_plan(): void
    {
        $sub = $this->createSubscription();
        $sub->pendingPlanId = 'plan_pro';

        $nextPeriod = BillingPeriod::starting(new DateTimeImmutable('2026-02-01 00:00:00'), BillingInterval::MONTH, 1);
        $sub->renew($nextPeriod, new DateTimeImmutable('2026-02-01 00:00:00'));

        $this->assertSame('plan_pro', $sub->planId);
        $this->assertNull($sub->pendingPlanId);
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status);
        $this->assertEquals($nextPeriod->start, $sub->currentPeriodStart);
        $this->assertEquals($nextPeriod->end, $sub->currentPeriodEnd);
    }

    public function test_past_due_and_reactivate(): void
    {
        $sub = $this->createSubscription();
        $now = new DateTimeImmutable('2026-01-10 00:00:00');

        $sub->markPastDue($now);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $sub->status);

        $sub->reactivate($now);
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status);
    }

    public function test_pause_and_resume_extends_current_period(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $end = new DateTimeImmutable('2026-02-01 00:00:00');
        $sub = $this->createSubscription(SubscriptionStatus::ACTIVE, $start, $end);

        $pauseTime = new DateTimeImmutable('2026-01-10 00:00:00');
        $sub->pause($pauseTime);
        $this->assertSame(SubscriptionStatus::PAUSED, $sub->status);
        $this->assertSame($pauseTime, $sub->pausedAt);

        // Resume 5 days later
        $resumeTime = new DateTimeImmutable('2026-01-15 00:00:00');
        $sub->resume($resumeTime);

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status);
        $this->assertNull($sub->pausedAt);
        // Original end was Feb 1. Extended by 5 days (432000 seconds) -> Feb 6
        $this->assertSame('2026-02-06 00:00:00', $sub->currentPeriodEnd->format('Y-m-d H:i:s'));
    }

    public function test_cancel_at_period_end_and_undo(): void
    {
        $sub = $this->createSubscription();
        $now = new DateTimeImmutable('2026-01-10 00:00:00');

        $sub->cancel($now, atPeriodEnd: true);
        $this->assertTrue($sub->cancelAtPeriodEnd);
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status);
        $this->assertSame($now, $sub->canceledAt);

        $sub->undoCancellation($now);
        $this->assertFalse($sub->cancelAtPeriodEnd);
        $this->assertNull($sub->canceledAt);
    }

    public function test_cancel_immediately(): void
    {
        $sub = $this->createSubscription();
        $now = new DateTimeImmutable('2026-01-10 00:00:00');

        $sub->cancel($now, atPeriodEnd: false);
        $this->assertSame(SubscriptionStatus::CANCELED, $sub->status);
        $this->assertFalse($sub->cancelAtPeriodEnd);
        $this->assertSame($now, $sub->endedAt);

        // Cannot cancel already canceled
        $this->expectException(InvalidSubscriptionStateException::class);
        $sub->cancel($now);
    }

    public function test_expire(): void
    {
        $sub = $this->createSubscription();
        $now = new DateTimeImmutable('2026-02-01 00:00:00');

        $sub->expire($now);
        $this->assertSame(SubscriptionStatus::EXPIRED, $sub->status);
        $this->assertSame($now, $sub->endedAt);

        $this->expectException(InvalidSubscriptionStateException::class);
        $sub->expire($now);
    }

    public function test_apply_plan_immediately(): void
    {
        $sub = $this->createSubscription();
        $now = new DateTimeImmutable('2026-01-10 00:00:00');

        $sub->applyPlan('plan_enterprise', $now);
        $this->assertSame('plan_enterprise', $sub->planId);
        $this->assertNull($sub->pendingPlanId);
    }
}
