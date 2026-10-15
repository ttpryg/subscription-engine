<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Integration\Repositories;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Entities\Plan;
use Ttpryg\SubscriptionEngine\Entities\Subscription;
use Ttpryg\SubscriptionEngine\Entities\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;
use Ttpryg\SubscriptionEngine\Enums\InvoiceStatus;
use Ttpryg\SubscriptionEngine\Enums\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Repositories\PdoInvoiceRepository;
use Ttpryg\SubscriptionEngine\Repositories\PdoPlanRepository;
use Ttpryg\SubscriptionEngine\Repositories\PdoSubscriptionRepository;

class PdoRepositoriesTest extends TestCase
{
    private PDO $pdo;

    private PdoPlanRepository $planRepo;

    private PdoSubscriptionRepository $subRepo;

    private PdoInvoiceRepository $invRepo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Run schema
        $schema = file_get_contents(__DIR__.'/../../../database/schema.sql');
        $this->assertIsString($schema);
        $this->pdo->exec($schema);

        $this->planRepo = new PdoPlanRepository($this->pdo);
        $this->subRepo = new PdoSubscriptionRepository($this->pdo);
        $this->invRepo = new PdoInvoiceRepository($this->pdo);
    }

    public function test_plan_repository_crud_and_atomic_upsert(): void
    {
        $plan = new Plan(
            id: 'plan_1',
            name: 'Standard',
            price: 50000.0,
            interval: BillingInterval::MONTH,
            trialDays: 7
        );

        // Insert
        $this->planRepo->save($plan);
        $found = $this->planRepo->findById('plan_1');
        $this->assertNotNull($found);
        $this->assertSame('Standard', $found->name);
        $this->assertSame(50000.0, $found->price);

        // Update (Atomic Upsert)
        $plan->changePrice(75000.0);
        $this->planRepo->save($plan);
        $updated = $this->planRepo->findById('plan_1');
        $this->assertSame(75000.0, $updated?->price);

        // Active list
        $active = $this->planRepo->findActive();
        $this->assertCount(1, $active);
    }

    public function test_subscription_repository_crud_and_queries(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $plan = new Plan('plan_1', 'Plan 1', 50000.0, BillingInterval::MONTH);
        $this->planRepo->save($plan);

        $sub = new Subscription(
            id: 'sub_1',
            userId: 'user_1',
            planId: 'plan_1',
            status: SubscriptionStatus::ACTIVE,
            currentPeriodStart: $now,
            currentPeriodEnd: $now->modify('+1 month')
        );

        $this->subRepo->save($sub);

        $found = $this->subRepo->findById('sub_1');
        $this->assertNotNull($found);
        $this->assertSame('user_1', $found->userId);
        $this->assertSame(SubscriptionStatus::ACTIVE, $found->status);

        // Update status and save
        $sub->markPastDue($now->modify('+5 days'));
        $this->subRepo->save($sub);

        $updated = $this->subRepo->findById('sub_1');
        $this->assertSame(SubscriptionStatus::PAST_DUE, $updated?->status);

        // Find by user
        $userSubs = $this->subRepo->findByUser('user_1');
        $this->assertCount(1, $userSubs);

        // Find due for renewal
        $due = $this->subRepo->findDueForRenewal($now->modify('+2 months'));
        $this->assertCount(1, $due);

        $notDue = $this->subRepo->findDueForRenewal($now->modify('+15 days'));
        $this->assertCount(0, $notDue);
    }

    public function test_invoice_repository_crud_and_queries(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        $plan = new Plan('plan_1', 'Plan 1', 50000.0, BillingInterval::MONTH);
        $this->planRepo->save($plan);

        $sub = new Subscription(
            id: 'sub_1',
            userId: 'user_1',
            planId: 'plan_1',
            status: SubscriptionStatus::ACTIVE,
            currentPeriodStart: $now,
            currentPeriodEnd: $now->modify('+1 month')
        );
        $this->subRepo->save($sub);

        $invoice = new SubscriptionInvoice(
            id: 'inv_1',
            subscriptionId: 'sub_1',
            userId: 'user_1',
            planId: 'plan_1',
            amount: 50000.0,
            currency: 'IDR',
            periodStart: $now,
            periodEnd: $now->modify('+1 month'),
            dueAt: $now->modify('+3 days')
        );

        $this->invRepo->save($invoice);

        $found = $this->invRepo->findById('inv_1');
        $this->assertNotNull($found);
        $this->assertSame(50000.0, $found->amount);
        $this->assertSame(InvoiceStatus::PENDING, $found->status);
        $this->assertSame(0, $found->attemptCount);

        // Mark failed and re-save
        $invoice->markFailed();
        $this->invRepo->save($invoice);

        $updated = $this->invRepo->findById('inv_1');
        $this->assertNotNull($updated);
        $this->assertSame(InvoiceStatus::FAILED, $updated->status);
        $this->assertSame(1, $updated->attemptCount);

        // Query overdue
        $overdue = $this->invRepo->findOverdue($now->modify('+5 days'));
        $this->assertCount(1, $overdue);

        $notOverdue = $this->invRepo->findOverdue($now->modify('+1 day'));
        $this->assertCount(0, $notOverdue);
    }
}
