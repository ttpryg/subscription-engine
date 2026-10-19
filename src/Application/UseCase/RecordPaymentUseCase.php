<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Application\DTO\RecordPaymentRequest;
use Ttpryg\SubscriptionEngine\Application\Service\InvoiceService;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Enum\InvoiceStatus;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionPaymentSucceededEvent;
use Ttpryg\SubscriptionEngine\Domain\Exception\InvoiceNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Exception\SubscriptionNotFoundException;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\Repository\SubscriptionRepositoryInterface;

final class RecordPaymentUseCase
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly InvoiceService $invoiceService,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function execute(RecordPaymentRequest $request): SubscriptionInvoice
    {
        $invoice = $this->invoiceRepository->findById($request->invoiceId)
            ?? throw InvoiceNotFoundException::withId($request->invoiceId);

        $wasAlreadyPaid = $invoice->status === InvoiceStatus::PAID;
        $now = $this->clock->now();

        $invoice->markPaid($now, $request->paymentReference);
        $this->invoiceRepository->save($invoice);

        $subscription = $this->subscriptionRepository->findById($invoice->subscriptionId)
            ?? throw SubscriptionNotFoundException::withId($invoice->subscriptionId);

        if ($subscription->status === SubscriptionStatus::PAST_DUE && ! $this->invoiceService->hasOutstanding($subscription->id)) {
            $subscription->reactivate($now);
            $this->subscriptionRepository->save($subscription);
        }

        if (! $wasAlreadyPaid) {
            $this->eventDispatcher?->dispatch(new SubscriptionPaymentSucceededEvent($subscription, $invoice));
        }

        return $invoice;
    }
}
