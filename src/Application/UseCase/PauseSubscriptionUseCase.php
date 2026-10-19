<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPausedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class PauseSubscriptionUseCase
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(string $subscriptionId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId)
            ?? throw SubscriptionNotFoundException::withId($subscriptionId);

        $subscription->pause($this->clock->now());
        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionPausedEvent($subscription));

        return $subscription;
    }
}
