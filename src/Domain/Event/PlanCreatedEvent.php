<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Event;

use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;

class PlanCreatedEvent
{
    public function __construct(public readonly Plan $plan) {}
}
