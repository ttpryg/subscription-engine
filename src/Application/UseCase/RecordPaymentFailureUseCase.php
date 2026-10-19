<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\UseCase;

use Closure;
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
    private readonly ?Closure $transactionManager;

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?callable $transactionManager = null
    ) {
        $this->transactionManager = $transactionManager !== null ? Closure::fromCallable($transactionManager) : null;
    }

    public function execute(RecordPaymentFailureRequest $request): SubscriptionInvoice
    {
        $operation = fn (): SubscriptionInvoice => $this->doExecute($request);

        return $this->transactionManager !== null
            ? ($this->transactionManager)($operation)
            : $operation();
    }

    private function doExecute(RecordPaymentFailureRequest $request): SubscriptionInvoice
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
