<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\RecordPaymentFailureRequest;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPaymentFailedEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\InvoiceNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class RecordPaymentFailureUseCase
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(RecordPaymentFailureRequest $request): SubscriptionInvoice
    {
        $invoice = $this->invoiceRepository->findById($request->invoiceId)
            ?? throw InvoiceNotFoundException::withId($request->invoiceId);

        $invoice->markFailed();
        $this->invoiceRepository->save($invoice);

        $subscription = $this->subscriptionRepository->findById($invoice->subscriptionId)
            ?? throw SubscriptionNotFoundException::withId($invoice->subscriptionId);

        $this->eventDispatcher?->dispatch(new SubscriptionPaymentFailedEvent($subscription, $invoice, $request->reason));

        return $invoice;
    }
}
