<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Entities;

use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Entities\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Enums\InvoiceStatus;

class SubscriptionInvoiceTest extends TestCase
{
    public function test_invoice_creation_and_status_checks(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
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

        $this->assertTrue($invoice->isPending());
        $this->assertTrue($invoice->isOutstanding());
        $this->assertFalse($invoice->isOverdue($now));
        $this->assertTrue($invoice->isOverdue($now->modify('+4 days')));
        $this->assertSame(0, $invoice->attemptCount);
    }

    public function test_mark_paid_is_idempotent(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
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

        $paidTime = new DateTimeImmutable('2026-01-02 12:00:00');
        $invoice->markPaid($paidTime, 'ref_123');

        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertSame('ref_123', $invoice->paymentReference);
        $this->assertSame($paidTime, $invoice->paidAt);

        // Calling markPaid again should be idempotent and not throw
        $invoice->markPaid($paidTime, 'ref_123');
        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
    }

    public function test_mark_failed_increments_attempt_count(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
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

        $invoice->markFailed();
        $this->assertSame(InvoiceStatus::FAILED, $invoice->status);
        $this->assertSame(1, $invoice->attemptCount);
        $this->assertTrue($invoice->isOutstanding());

        $invoice->markFailed();
        $this->assertSame(2, $invoice->attemptCount);
    }

    public function test_void_invoice(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
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

        $invoice->void();
        $this->assertSame(InvoiceStatus::VOID, $invoice->status);
        $this->assertFalse($invoice->isOutstanding());

        // Cannot mark paid if void
        $this->expectException(DomainException::class);
        $invoice->markPaid($now);
    }

    public function test_cannot_void_already_paid_invoice(): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00');
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

        $invoice->markPaid($now);
        $this->expectException(DomainException::class);
        $invoice->void();
    }
}
