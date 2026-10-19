<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Event;

use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;

class SubscriptionPlanChangedEvent extends SubscriptionEvent
{
    public function __construct(
        Subscription $subscription,
        public readonly string $fromPlanId,
        public readonly string $toPlanId
    ) {
        parent::__construct($subscription);
    }
}
