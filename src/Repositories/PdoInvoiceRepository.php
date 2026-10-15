<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\SubscriptionEngine\Contracts\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Entities\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Enums\InvoiceStatus;
use Ttpryg\SubscriptionEngine\Repositories\Concerns\InteractsWithPdo;

class PdoInvoiceRepository implements InvoiceRepositoryInterface
{
    use InteractsWithPdo;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'subscription_invoices'
    ) {}

    public function save(SubscriptionInvoice $invoice): void
    {
        $this->upsert($this->pdo, $this->table, [
            'id' => $invoice->id,
            'subscription_id' => $invoice->subscriptionId,
            'user_id' => $invoice->userId,
            'plan_id' => $invoice->planId,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'status' => $invoice->status->value,
            'period_start' => self::toDb($invoice->periodStart),
            'period_end' => self::toDb($invoice->periodEnd),
            'due_at' => self::toDb($invoice->dueAt),
            'paid_at' => self::toDb($invoice->paidAt),
            'payment_reference' => $invoice->paymentReference,
            'attempt_count' => $invoice->attemptCount,
            'created_at' => self::toDb($invoice->createdAt),
        ]);
    }

    public function findById(string $id): ?SubscriptionInvoice
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE id = :id', $this->table));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySubscription(string $subscriptionId): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE subscription_id = :subscription_id ORDER BY period_start ASC',
            $this->table
        ));
        $stmt->execute(['subscription_id' => $subscriptionId]);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findOverdue(DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE status IN (:pending, :failed) AND due_at <= :now ORDER BY due_at ASC',
            $this->table
        ));
        $stmt->execute([
            'pending' => InvoiceStatus::PENDING->value,
            'failed' => InvoiceStatus::FAILED->value,
            'now' => self::toDb($now),
        ]);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SubscriptionInvoice
    {
        return new SubscriptionInvoice(
            id: (string) $row['id'],
            subscriptionId: (string) $row['subscription_id'],
            userId: (string) $row['user_id'],
            planId: (string) $row['plan_id'],
            amount: (float) $row['amount'],
            currency: (string) $row['currency'],
            periodStart: new DateTimeImmutable((string) $row['period_start']),
            periodEnd: new DateTimeImmutable((string) $row['period_end']),
            dueAt: new DateTimeImmutable((string) $row['due_at']),
            status: InvoiceStatus::from((string) $row['status']),
            paidAt: self::fromDb($row['paid_at'] ?? null),
            paymentReference: $row['payment_reference'] ?? null,
            attemptCount: (int) ($row['attempt_count'] ?? 0),
            createdAt: self::fromDb($row['created_at'])
        );
    }
}
