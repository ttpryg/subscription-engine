<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Entities;

use DateTimeImmutable;
use DomainException;
use Ttpryg\SubscriptionEngine\Enums\InvoiceStatus;

class SubscriptionInvoice
{
    public readonly DateTimeImmutable $createdAt;

    public function __construct(
        public readonly string $id,
        public readonly string $subscriptionId,
        public readonly string $userId,
        public readonly string $planId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly DateTimeImmutable $periodStart,
        public readonly DateTimeImmutable $periodEnd,
        public readonly DateTimeImmutable $dueAt,
        public InvoiceStatus $status = InvoiceStatus::PENDING,
        public ?DateTimeImmutable $paidAt = null,
        public ?string $paymentReference = null,
        public int $attemptCount = 0,
        ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
    }

    public function isPending(): bool
    {
        return $this->status === InvoiceStatus::PENDING;
    }

    /** Pending or failed: still awaiting a successful payment. */
    public function isOutstanding(): bool
    {
        return $this->status === InvoiceStatus::PENDING || $this->status === InvoiceStatus::FAILED;
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $this->isOutstanding() && $now >= $this->dueAt;
    }

    public function markPaid(DateTimeImmutable $paidAt, ?string $paymentReference = null): void
    {
        if ($this->status === InvoiceStatus::PAID) {
            if ($paymentReference !== null && $this->paymentReference === null) {
                $this->paymentReference = $paymentReference;
            }

            return;
        }

        if ($this->status === InvoiceStatus::VOID) {
            throw new DomainException(sprintf('Invoice "%s" is already %s', $this->id, $this->status->value));
        }

        $this->status = InvoiceStatus::PAID;
        $this->paidAt = $paidAt;
        $this->paymentReference = $paymentReference;
    }

    /** A failed attempt keeps the invoice payable (retry) until it is voided. */
    public function markFailed(): void
    {
        if (! $this->isPending() && $this->status !== InvoiceStatus::FAILED) {
            throw new DomainException(sprintf('Invoice "%s" is already %s', $this->id, $this->status->value));
        }

        $this->status = InvoiceStatus::FAILED;
        $this->attemptCount++;
    }

    public function void(): void
    {
        if ($this->status === InvoiceStatus::PAID) {
            throw new DomainException(sprintf('Invoice "%s" is already paid', $this->id));
        }

        $this->status = InvoiceStatus::VOID;
    }
}
