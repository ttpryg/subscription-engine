<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Services;

use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Contracts\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Clock\FixedClock;
use Ttpryg\SubscriptionEngine\Entities\Plan;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;
use Ttpryg\SubscriptionEngine\Enums\InvoiceStatus;
use Ttpryg\SubscriptionEngine\Enums\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Events\SubscriptionCreatedEvent;
use Ttpryg\SubscriptionEngine\Events\SubscriptionPaymentSucceededEvent;
use Ttpryg\SubscriptionEngine\Repositories\MemoryInvoiceRepository;
use Ttpryg\SubscriptionEngine\Repositories\MemoryPlanRepository;
use Ttpryg\SubscriptionEngine\Repositories\MemorySubscriptionRepository;
use Ttpryg\SubscriptionEngine\Services\SubscriptionService;

class SubscriptionServiceTest extends TestCase
{
    private MemoryPlanRepository $planRepo;

    private MemorySubscriptionRepository $subRepo;

    private MemoryInvoiceRepository $invRepo;

    private FixedClock $clock;

    /** @var object[] */
    public array $dispatchedEvents = [];

    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->planRepo = new MemoryPlanRepository;
        $this->subRepo = new MemorySubscriptionRepository;
        $this->invRepo = new MemoryInvoiceRepository;
        $this->clock = new FixedClock(new DateTimeImmutable('2026-01-01 00:00:00'));
        $this->dispatchedEvents = [];

        $dispatcher = new class($this) implements EventDispatcherInterface
        {
            public function __construct(private readonly SubscriptionServiceTest $test) {}

            public function dispatch(object $event): object
            {
                $this->test->dispatchedEvents[] = $event;

                return $event;
            }
        };

        $this->service = new SubscriptionService(
            planRepository: $this->planRepo,
            subscriptionRepository: $this->subRepo,
            invoiceRepository: $this->invRepo,
            clock: $this->clock,
            eventDispatcher: $dispatcher,
            graceDays: 3,
            idGenerator: fn (): string => 'inv_'.uniqid()
        );

        // Seed default plans
        $this->planRepo->save(new Plan(
            id: 'plan_monthly',
            name: 'Monthly Plan',
            price: 100000.0,
            interval: BillingInterval::MONTH,
            trialDays: 14
        ));

        $this->planRepo->save(new Plan(
            id: 'plan_pro',
            name: 'Pro Plan',
            price: 200000.0,
            interval: BillingInterval::MONTH,
            trialDays: 0
        ));
    }

    public function test_subscribe_with_trial(): void
    {
        $sub = $this->service->subscribe('sub_1', 'user_1', 'plan_monthly');

        $this->assertSame(SubscriptionStatus::TRIALING, $sub->status);
        $this->assertEquals(new DateTimeImmutable('2026-01-15 00:00:00'), $sub->trialEndsAt);
        $this->assertEquals(new DateTimeImmutable('2026-01-15 00:00:00'), $sub->currentPeriodEnd);
        $this->assertCount(0, $this->service->getInvoices('sub_1'));

        $this->assertCount(1, $this->dispatchedEvents);
        $this->assertInstanceOf(SubscriptionCreatedEvent::class, $this->dispatchedEvents[0]);
    }

    public function test_subscribe_skipping_trial_issues_invoice(): void
    {
        $sub = $this->service->subscribe('sub_1', 'user_1', 'plan_monthly', skipTrial: true);

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status);
        $this->assertNull($sub->trialEndsAt);
        $this->assertEquals(new DateTimeImmutable('2026-02-01 00:00:00'), $sub->currentPeriodEnd);

        $invoices = $this->service->getInvoices('sub_1');
        $this->assertCount(1, $invoices);
        $this->assertSame(100000.0, $invoices[0]->amount);
        $this->assertSame(InvoiceStatus::PENDING, $invoices[0]->status);
    }

    public function test_subscribe_inactive_plan_throws_exception(): void
    {
        $plan = $this->planRepo->findById('plan_monthly');
        $plan?->deactivate();

        $this->expectException(DomainException::class);
        $this->service->subscribe('sub_1', 'user_1', 'plan_monthly');
    }

    public function test_has_access(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $this->assertTrue($this->service->hasAccess('user_1'));
        $this->assertTrue($this->service->hasAccess('user_1', 'plan_pro'));
        $this->assertFalse($this->service->hasAccess('user_1', 'plan_monthly'));
        $this->assertFalse($this->service->hasAccess('user_2'));
    }

    public function test_record_payment_and_idempotency(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $invoices = $this->service->getInvoices('sub_1');
        $this->assertCount(1, $invoices);
        $invoice = $invoices[0];

        // 1st payment record
        $paidInvoice = $this->service->recordPayment($invoice->id, 'ref_tx_123');
        $this->assertSame(InvoiceStatus::PAID, $paidInvoice->status);
        $this->assertSame('ref_tx_123', $paidInvoice->paymentReference);

        $succeededEvents = array_filter($this->dispatchedEvents, fn (object $e): bool => $e instanceof SubscriptionPaymentSucceededEvent);
        $this->assertCount(1, $succeededEvents);

        // 2nd payment record with same reference should be idempotent
        $secondResult = $this->service->recordPayment($invoice->id, 'ref_tx_123');
        $this->assertSame(InvoiceStatus::PAID, $secondResult->status);
        // Event should NOT be dispatched a second time
        $succeededEvents2 = array_filter($this->dispatchedEvents, fn (object $e): bool => $e instanceof SubscriptionPaymentSucceededEvent);
        $this->assertCount(1, $succeededEvents2);
    }

    public function test_payment_reactivates_past_due_subscription(): void
    {
        $sub = $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $invoice = $this->service->getInvoices('sub_1')[0];

        $sub->markPastDue($this->clock->now());
        $this->subRepo->save($sub);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $this->service->getSubscription('sub_1')->status);

        $this->service->recordPayment($invoice->id);
        $this->assertSame(SubscriptionStatus::ACTIVE, $this->service->getSubscription('sub_1')->status);
    }

    public function test_record_payment_failure(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $invoice = $this->service->getInvoices('sub_1')[0];

        $this->service->recordPaymentFailure($invoice->id, 'insufficient_funds');
        $retrieved = $this->invRepo->findById($invoice->id);
        $this->assertNotNull($retrieved);
        $this->assertSame(InvoiceStatus::FAILED, $retrieved->status);
        $this->assertSame(1, $retrieved->attemptCount);
    }

    public function test_change_plan_scheduled(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_monthly', skipTrial: true);
        $this->service->changePlan('sub_1', 'plan_pro', immediate: false);

        $updated = $this->service->getSubscription('sub_1');
        $this->assertSame('plan_monthly', $updated->planId);
        $this->assertSame('plan_pro', $updated->pendingPlanId);
    }

    public function test_change_plan_immediate_with_proration(): void
    {
        // Subscribe to monthly plan (100k) on 2026-01-01, period ends 2026-02-01 (31 days)
        $this->service->subscribe('sub_1', 'user_1', 'plan_monthly', skipTrial: true);

        // Advance 15.5 days (approx halfway)
        $this->clock->set(new DateTimeImmutable('2026-01-16 12:00:00'));

        // Upgrade immediately to pro plan (200k)
        $this->service->changePlan('sub_1', 'plan_pro', immediate: true);

        $updated = $this->service->getSubscription('sub_1');
        $this->assertSame('plan_pro', $updated->planId);
        $this->assertNull($updated->pendingPlanId);

        $invoices = $this->service->getInvoices('sub_1');
        // Initial invoice + Prorated upgrade invoice
        $this->assertCount(2, $invoices);
        $proratedInvoice = $invoices[1];
        // Halfway through: approx 50k difference (around 50000 IDR)
        $this->assertGreaterThan(40000.0, $proratedInvoice->amount);
        $this->assertLessThan(60000.0, $proratedInvoice->amount);
    }

    public function test_cancel_at_period_end_and_undo(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $this->service->cancel('sub_1', atPeriodEnd: true);

        $sub = $this->service->getSubscription('sub_1');
        $this->assertTrue($sub->cancelAtPeriodEnd);
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status);

        $this->service->undoCancellation('sub_1');
        $sub2 = $this->service->getSubscription('sub_1');
        $this->assertFalse($sub2->cancelAtPeriodEnd);
    }

    public function test_cancel_immediate_voids_outstanding_invoices(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $invoice = $this->service->getInvoices('sub_1')[0];
        $this->assertTrue($invoice->isOutstanding());

        $this->service->cancel('sub_1', atPeriodEnd: false);
        $sub = $this->service->getSubscription('sub_1');
        $this->assertSame(SubscriptionStatus::CANCELED, $sub->status);

        $invoiceAfter = $this->invRepo->findById($invoice->id);
        $this->assertSame(InvoiceStatus::VOID, $invoiceAfter?->status);
    }

    public function test_pause_and_resume(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $this->service->pause('sub_1');

        $sub = $this->service->getSubscription('sub_1');
        $this->assertSame(SubscriptionStatus::PAUSED, $sub->status);

        $this->clock->advance('+5 days');
        $this->service->resume('sub_1');

        $resumed = $this->service->getSubscription('sub_1');
        $this->assertSame(SubscriptionStatus::ACTIVE, $resumed->status);
        $this->assertSame('2026-02-06 00:00:00', $resumed->currentPeriodEnd->format('Y-m-d H:i:s'));
    }

    public function test_process_renewals(): void
    {
        // 1. Subscribe user_1 (period ends Feb 1, invoice due Jan 4)
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $inv1 = $this->service->getInvoices('sub_1')[0];

        // Fast forward to Jan 5 (invoice is now overdue, graceDays = 3)
        $this->clock->set(new DateTimeImmutable('2026-01-05 00:00:00'));

        $summary = $this->service->processRenewals();
        $this->assertSame(1, $summary['past_due']);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $this->service->getSubscription('sub_1')->status);

        // Pay the invoice to reactivate
        $this->service->recordPayment($inv1->id);
        $this->assertSame(SubscriptionStatus::ACTIVE, $this->service->getSubscription('sub_1')->status);

        // Fast forward to Feb 2 (period ended on Feb 1, renewal is due)
        $this->clock->set(new DateTimeImmutable('2026-02-02 00:00:00'));

        $summary2 = $this->service->processRenewals();
        $this->assertSame(1, $summary2['renewed']);

        $renewedSub = $this->service->getSubscription('sub_1');
        $this->assertSame(SubscriptionStatus::ACTIVE, $renewedSub->status);
        $this->assertEquals(new DateTimeImmutable('2026-03-01 00:00:00'), $renewedSub->currentPeriodEnd);

        // New renewal invoice issued
        $invoices = $this->service->getInvoices('sub_1');
        $this->assertCount(2, $invoices);
    }

    public function test_process_renewals_expires_when_cancel_at_period_end(): void
    {
        $this->service->subscribe('sub_1', 'user_1', 'plan_pro');
        $this->service->cancel('sub_1', atPeriodEnd: true);

        // Fast forward past currentPeriodEnd
        $this->clock->set(new DateTimeImmutable('2026-02-05 00:00:00'));

        $summary = $this->service->processRenewals();
        $this->assertSame(1, $summary['expired']);
        $this->assertSame(SubscriptionStatus::EXPIRED, $this->service->getSubscription('sub_1')->status);
    }
}
