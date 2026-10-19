<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Repository;

use DateTimeImmutable;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;

interface SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void;

    public function findById(string $id): ?Subscription;

    /** @return Subscription[] */
    public function findByUser(string $userId, ?string $storeId = null): array;

    /**
     * Subscriptions in TRIALING, ACTIVE or PAST_DUE status whose current period ended at or before $now.
     *
     * @return Subscription[]
     */
    public function findDueForRenewal(DateTimeImmutable $now): array;
}
