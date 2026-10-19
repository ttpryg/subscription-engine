<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Infrastructure\Persistence\Pdo;

use DateTimeImmutable;
use PDO;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;
use Ttpryg\SubscriptionEngine\Infrastructure\Persistence\Pdo\Concerns\InteractsWithPdo;

class PdoSubscriptionRepository implements SubscriptionRepositoryInterface
{
    use InteractsWithPdo;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'subscriptions'
    ) {}

    public function save(Subscription $subscription): void
    {
        $this->upsert($this->pdo, $this->table, [
            'id' => $subscription->id,
            'user_id' => $subscription->userId,
            'plan_id' => $subscription->planId,
            'pending_plan_id' => $subscription->pendingPlanId,
            'status' => $subscription->status->value,
            'store_id' => $subscription->storeId,
            'current_period_start' => self::toDb($subscription->currentPeriodStart),
            'current_period_end' => self::toDb($subscription->currentPeriodEnd),
            'trial_ends_at' => self::toDb($subscription->trialEndsAt),
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd ? 1 : 0,
            'canceled_at' => self::toDb($subscription->canceledAt),
            'paused_at' => self::toDb($subscription->pausedAt),
            'ended_at' => self::toDb($subscription->endedAt),
            'created_at' => self::toDb($subscription->createdAt),
            'updated_at' => self::toDb($subscription->updatedAt),
        ]);
    }

    public function findById(string $id, bool $lock = false): ?Subscription
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = :id', $this->table);
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByUser(string $userId, ?string $storeId = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE user_id = :user_id', $this->table);
        $params = ['user_id' => $userId];

        if ($storeId !== null) {
            $sql .= ' AND store_id = :store_id';
            $params['store_id'] = $storeId;
        }

        $stmt = $this->pdo->prepare($sql.' ORDER BY created_at DESC');
        $stmt->execute($params);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findDueForRenewal(DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE status IN (:trialing, :active, :past_due) AND current_period_end <= :now ORDER BY current_period_end ASC',
            $this->table
        ));
        $stmt->execute([
            'trialing' => SubscriptionStatus::TRIALING->value,
            'active' => SubscriptionStatus::ACTIVE->value,
            'past_due' => SubscriptionStatus::PAST_DUE->value,
            'now' => self::toDb($now),
        ]);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Subscription
    {
        return new Subscription(
            id: (string) $row['id'],
            userId: (string) $row['user_id'],
            planId: (string) $row['plan_id'],
            status: SubscriptionStatus::from((string) $row['status']),
            currentPeriodStart: new DateTimeImmutable((string) $row['current_period_start']),
            currentPeriodEnd: new DateTimeImmutable((string) $row['current_period_end']),
            storeId: $row['store_id'] ?? null,
            trialEndsAt: self::fromDb($row['trial_ends_at'] ?? null),
            pendingPlanId: $row['pending_plan_id'] ?? null,
            cancelAtPeriodEnd: (bool) $row['cancel_at_period_end'],
            canceledAt: self::fromDb($row['canceled_at'] ?? null),
            pausedAt: self::fromDb($row['paused_at'] ?? null),
            endedAt: self::fromDb($row['ended_at'] ?? null),
            createdAt: self::fromDb($row['created_at']),
            updatedAt: self::fromDb($row['updated_at'])
        );
    }
}
