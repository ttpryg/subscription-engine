<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Domain\Event;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Event\PlanCreatedEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionCanceledEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionCreatedEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionExpiredEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPastDueEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPausedEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPaymentFailedEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPaymentSucceededEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPlanChangedEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionRenewalDueEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionRenewedEvent;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionResumedEvent;

class DomainEventsTest extends TestCase
{
    public function test_simple_lifecycle_events_expose_the_subscription(): void
    {
        $subscription = $this->subscription();

        $eventClasses = [
            SubscriptionCreatedEvent::class,
            SubscriptionPausedEvent::class,
            SubscriptionResumedEvent::class,
            SubscriptionCanceledEvent::class,
            SubscriptionExpiredEvent::class,
        ];

        foreach ($eventClasses as $eventClass) {
            $event = new $eventClass($subscription);

            $this->assertInstanceOf(SubscriptionEvent::class, $event);
            $this->assertSame($subscription, $event->subscription);
        }
    }

    public function test_plan_created_event_exposes_the_plan(): void
    {
        $plan = new Plan('plan_1', 'Plan 1', 50000.0, BillingInterval::MONTH);

        $event = new PlanCreatedEvent($plan);

        $this->assertSame($plan, $event->plan);
    }

    public function test_invoice_events_expose_the_subscription_and_invoice(): void
    {
        $subscription = $this->subscription();
        $invoice = $this->invoice();

        $eventClasses = [
            SubscriptionPastDueEvent::class,
            SubscriptionPaymentSucceededEvent::class,
            SubscriptionRenewalDueEvent::class,
            SubscriptionRenewedEvent::class,
        ];

        foreach ($eventClasses as $eventClass) {
            $event = new $eventClass($subscription, $invoice);

            $this->assertSame($subscription, $event->subscription);
            $this->assertSame($invoice, $event->invoice);
        }
    }

    public function test_payment_failed_event_carries_an_optional_reason(): void
    {
        $event = new SubscriptionPaymentFailedEvent($this->subscription(), $this->invoice(), 'insufficient_funds');
        $withoutReason = new SubscriptionPaymentFailedEvent($this->subscription(), $this->invoice());

        $this->assertSame('insufficient_funds', $event->reason);
        $this->assertNull($withoutReason->reason);
    }

    public function test_plan_changed_event_exposes_the_transition(): void
    {
        $event = new SubscriptionPlanChangedEvent($this->subscription(), 'plan_old', 'plan_new');

        $this->assertSame('plan_old', $event->fromPlanId);
        $this->assertSame('plan_new', $event->toPlanId);
    }

    private function subscription(): Subscription
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');

        return new Subscription(
            id: 'sub_1',
            userId: 'user_1',
            planId: 'plan_1',
            status: SubscriptionStatus::ACTIVE,
            currentPeriodStart: $start,
            currentPeriodEnd: $start->modify('+1 month')
        );
    }

    private function invoice(): SubscriptionInvoice
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');

        return new SubscriptionInvoice(
            id: 'inv_1',
            subscriptionId: 'sub_1',
            userId: 'user_1',
            planId: 'plan_1',
            amount: 50000.0,
            currency: 'IDR',
            periodStart: $start,
            periodEnd: $start->modify('+1 month'),
            dueAt: $start->modify('+3 days')
        );
    }
}
