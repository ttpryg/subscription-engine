<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Event;

use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;
use Ttpryg\SubscriptionEngine\Domain\Entity\SubscriptionInvoice;

class SubscriptionPaymentFailedEvent extends SubscriptionEvent
{
    public function __construct(
        Subscription $subscription,
        public readonly SubscriptionInvoice $invoice,
        public readonly ?string $reason = null
    ) {
        parent::__construct($subscription);
    }
}
