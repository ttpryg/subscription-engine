<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\Service;

use Psr\Clock\ClockInterface;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class SubscriptionQueryService
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly ClockInterface $clock
    ) {}

    public function getSubscription(string $id): Subscription
    {
        return $this->subscriptionRepository->findById($id) ?? throw SubscriptionNotFoundException::withId($id);
    }

    /** @return Subscription[] */
    public function getUserSubscriptions(string $userId, ?string $storeId = null): array
    {
        return $this->subscriptionRepository->findByUser($userId, $storeId);
    }

    /** Access check, e.g. for feature gating / middleware. */
    public function hasAccess(string $userId, ?string $planId = null, ?string $storeId = null): bool
    {
        $now = $this->clock->now();

        foreach ($this->subscriptionRepository->findByUser($userId, $storeId) as $subscription) {
            if (($planId === null || $subscription->planId === $planId) && $subscription->hasAccess($now)) {
                return true;
            }
        }

        return false;
    }

    /** @return SubscriptionInvoice[] */
    public function getInvoices(string $subscriptionId): array
    {
        return $this->invoiceRepository->findBySubscription($subscriptionId);
    }
}
