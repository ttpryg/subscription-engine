<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\Service;

use Closure;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;
use Ttpryg\SubscriptionEngine\Domain\Event\SubscriptionRenewalDueEvent;
use Ttpryg\SubscriptionEngine\Domain\Repository\InvoiceRepositoryInterface;
use Ttpryg\SubscriptionEngine\Domain\ValueObject\BillingPeriod;

final class InvoiceService
{
    private readonly Closure $idGenerator;

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher,
        private readonly int $graceDays,
        callable $idGenerator
    ) {
        $this->idGenerator = Closure::fromCallable($idGenerator);
    }

    public function issueForPeriod(Subscription $subscription, Plan $plan, BillingPeriod $period): SubscriptionInvoice
    {
        $now = $this->clock->now();
        $invoice = $this->build($subscription, $plan, $plan->price, $period->start, $period->end, $now);

        // Free plans are settled immediately; nothing to collect.
        if ($invoice->amount <= 0) {
            $invoice->markPaid($now, 'free');
        }

        $this->invoiceRepository->save($invoice);

        if ($invoice->isOutstanding()) {
            $this->eventDispatcher?->dispatch(new SubscriptionRenewalDueEvent($subscription, $invoice));
        }

        return $invoice;
    }

    public function issueProrated(
        Subscription $subscription,
        Plan $plan,
        float $amount,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd
    ): ?SubscriptionInvoice {
        if ($amount <= 0) {
            return null;
        }

        $invoice = $this->build($subscription, $plan, $amount, $periodStart, $periodEnd, $this->clock->now());
        $this->invoiceRepository->save($invoice);
        $this->eventDispatcher?->dispatch(new SubscriptionRenewalDueEvent($subscription, $invoice));

        return $invoice;
    }

    public function hasOutstanding(string $subscriptionId): bool
    {
        foreach ($this->invoiceRepository->findBySubscription($subscriptionId) as $invoice) {
            if ($invoice->isOutstanding()) {
                return true;
            }
        }

        return false;
    }

    public function voidOutstanding(string $subscriptionId): void
    {
        foreach ($this->invoiceRepository->findBySubscription($subscriptionId) as $invoice) {
            if ($invoice->isOutstanding()) {
                $invoice->void();
                $this->invoiceRepository->save($invoice);
            }
        }
    }

    private function build(
        Subscription $subscription,
        Plan $plan,
        float $amount,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $now
    ): SubscriptionInvoice {
        return new SubscriptionInvoice(
            id: ($this->idGenerator)(),
            subscriptionId: $subscription->id,
            userId: $subscription->userId,
            planId: $plan->id,
            amount: $amount,
            currency: $plan->currency,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            dueAt: $periodStart->modify(sprintf('+%d days', $this->graceDays)),
            createdAt: $now
        );
    }
}
