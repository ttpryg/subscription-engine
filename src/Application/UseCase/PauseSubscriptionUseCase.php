<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Closure;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPausedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class PauseSubscriptionUseCase
{
    private readonly ?Closure $transactionManager;

    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?callable $transactionManager = null
    ) {
        $this->transactionManager = $transactionManager !== null ? Closure::fromCallable($transactionManager) : null;
    }

    public function execute(string $subscriptionId): Subscription
    {
        $operation = fn (): Subscription => $this->doExecute($subscriptionId);

        return $this->transactionManager !== null
            ? ($this->transactionManager)($operation)
            : $operation();
    }

    private function doExecute(string $subscriptionId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId, lock: true)
            ?? throw SubscriptionNotFoundException::withId($subscriptionId);

        $subscription->pause($this->clock->now());
        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionPausedEvent($subscription));

        return $subscription;
    }
}
