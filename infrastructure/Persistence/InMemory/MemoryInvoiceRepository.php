<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Infrastructure\Persistence\InMemory;

use DateTimeImmutable;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;

class MemoryInvoiceRepository implements InvoiceRepositoryInterface
{
    /** @var array<string, SubscriptionInvoice> */
    private array $invoices = [];

    public function save(SubscriptionInvoice $invoice): void
    {
        $this->invoices[$invoice->id] = $invoice;
    }

    public function findById(string $id): ?SubscriptionInvoice
    {
        return $this->invoices[$id] ?? null;
    }

    public function findBySubscription(string $subscriptionId): array
    {
        return array_values(array_filter(
            $this->invoices,
            static fn (SubscriptionInvoice $i): bool => $i->subscriptionId === $subscriptionId
        ));
    }

    public function findOverdue(DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->invoices,
            static fn (SubscriptionInvoice $i): bool => $i->isOverdue($now)
        ));
    }
}
