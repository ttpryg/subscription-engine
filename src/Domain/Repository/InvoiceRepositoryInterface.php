<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Repository;

use DateTimeImmutable;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;

interface InvoiceRepositoryInterface
{
    public function save(SubscriptionInvoice $invoice): void;

    public function findById(string $id): ?SubscriptionInvoice;

    /** @return SubscriptionInvoice[] */
    public function findBySubscription(string $subscriptionId): array;

    /**
     * Outstanding (PENDING or FAILED) invoices whose due date is at or before $now.
     *
     * @return SubscriptionInvoice[]
     */
    public function findOverdue(DateTimeImmutable $now): array;
}
