<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Psr\Clock\ClockInterface;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class UndoCancellationUseCase
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly ClockInterface $clock
    ) {}

    public function execute(string $subscriptionId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId)
            ?? throw SubscriptionNotFoundException::withId($subscriptionId);

        $subscription->undoCancellation($this->clock->now());
        $this->subscriptionRepository->save($subscription);

        return $subscription;
    }
}
