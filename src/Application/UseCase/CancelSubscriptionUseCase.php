<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\CancelSubscriptionRequest;
use Ttpryg\SubscriptionEngine\Application\Service\InvoiceService;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionCanceledEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class CancelSubscriptionUseCase
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceService $invoiceService,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(CancelSubscriptionRequest $request): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($request->subscriptionId)
            ?? throw SubscriptionNotFoundException::withId($request->subscriptionId);

        $subscription->cancel($this->clock->now(), $request->atPeriodEnd);

        if ($subscription->status === SubscriptionStatus::CANCELED) {
            $this->invoiceService->voidOutstanding($subscription->id);
        }

        $this->subscriptionRepository->save($subscription);
        $this->eventDispatcher?->dispatch(new SubscriptionCanceledEvent($subscription));

        return $subscription;
    }
}
