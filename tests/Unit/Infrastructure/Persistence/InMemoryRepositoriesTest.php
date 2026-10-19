<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Infrastructure\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\InMemory\MemoryInvoiceRepository;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\InMemory\MemoryPlanRepository;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\InMemory\MemorySubscriptionRepository;

class InMemoryRepositoriesTest extends TestCase
{
    public function test_plan_repository_saves_finds_and_filters_active_plans(): void
    {
        $repository = new MemoryPlanRepository;
        $repository->save(new Plan('plan_1', 'Plan 1', 50000.0, BillingInterval::MONTH, storeId: 'store_1'));
        $repository->save(new Plan('plan_2', 'Plan 2', 100000.0, BillingInterval::MONTH, storeId: 'store_2'));

        $inactive = new Plan('plan_3', 'Plan 3', 75000.0, BillingInterval::MONTH);
        $inactive->deactivate();
        $repository->save($inactive);

        $this->assertSame('Plan 1', $repository->findById('plan_1')?->name);
        $this->assertNull($repository->findById('missing'));
        $this->assertCount(2, $repository->findActive());
        $this->assertSame('plan_1', $repository->findActive('store_1')[0]->id);
    }

    public function test_plan_repository_overwrites_on_save(): void
    {
        $repository = new MemoryPlanRepository;
        $plan = new Plan('plan_1', 'Plan 1', 50000.0, BillingInterval::MONTH);
        $repository->save($plan);

        $plan->changePrice(65000.0);
        $repository->save($plan);

        $this->assertSame(65000.0, $repository->findById('plan_1')?->price);
    }

    public function test_subscription_repository_finds_by_user_and_store(): void
    {
        $repository = new MemorySubscriptionRepository;
        $repository->save($this->subscription('sub_1', 'user_1', 'store_1'));
        $repository->save($this->subscription('sub_2', 'user_1', 'store_2'));
        $repository->save($this->subscription('sub_3', 'user_2'));

        $this->assertCount(2, $repository->findByUser('user_1'));
        $this->assertSame('sub_1', $repository->findByUser('user_1', 'store_1')[0]->id);
        $this->assertSame('user_2', $repository->findById('sub_3')?->userId);
    }

    public function test_subscription_repository_finds_renewable_subscriptions(): void
    {
        $now = new DateTimeImmutable('2026-02-01 00:00:00');
        $repository = new MemorySubscriptionRepository;

        $due = $this->subscription('sub_due', 'user_1', end: '2026-01-15 00:00:00');
        $due->markPastDue(new DateTimeImmutable('2026-01-10 00:00:00'));
        $repository->save($due);

        $active = $this->subscription('sub_active', 'user_1', end: '2026-01-20 00:00:00');
        $repository->save($active);

        $expired = $this->subscription('sub_expired', 'user_1', end: '2026-01-20 00:00:00');
        $expired->expire(new DateTimeImmutable('2026-01-21 00:00:00'));
        $repository->save($expired);

        $future = $this->subscription('sub_future', 'user_1', end: '2026-03-01 00:00:00');
        $repository->save($future);

        $dueForRenewal = $repository->findDueForRenewal($now);

        $this->assertCount(2, $dueForRenewal);
        $this->assertSame(['sub_due', 'sub_active'], array_map(static fn (Subscription $s): string => $s->id, $dueForRenewal));
    }

    public function test_invoice_repository_finds_by_subscription_and_overdue_invoices(): void
    {
        $now = new DateTimeImmutable('2026-02-01 00:00:00');
        $repository = new MemoryInvoiceRepository;
        $repository->save($this->invoice('inv_1', 'sub_1', dueAt: '2026-01-20 00:00:00'));
        $repository->save($this->invoice('inv_2', 'sub_2', dueAt: '2026-01-20 00:00:00'));

        $paid = $this->invoice('inv_3', 'sub_1', dueAt: '2026-01-20 00:00:00');
        $paid->markPaid(new DateTimeImmutable('2026-01-19 00:00:00'));
        $repository->save($paid);

        $this->assertCount(2, $repository->findBySubscription('sub_1'));
        $this->assertSame('inv_1', $repository->findById('inv_1')?->id);
        $this->assertCount(2, $repository->findOverdue($now));
    }

    private function subscription(string $id, string $userId, ?string $storeId = null, string $end = '2026-02-01 00:00:00'): Subscription
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');

        return new Subscription(
            id: $id,
            userId: $userId,
            planId: 'plan_1',
            status: SubscriptionStatus::ACTIVE,
            currentPeriodStart: $start,
            currentPeriodEnd: new DateTimeImmutable($end),
            storeId: $storeId
        );
    }

    private function invoice(string $id, string $subscriptionId, string $dueAt): SubscriptionInvoice
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');

        return new SubscriptionInvoice(
            id: $id,
            subscriptionId: $subscriptionId,
            userId: 'user_1',
            planId: 'plan_1',
            amount: 50000.0,
            currency: 'IDR',
            periodStart: $start,
            periodEnd: $start->modify('+1 month'),
            dueAt: new DateTimeImmutable($dueAt)
        );
    }
}
