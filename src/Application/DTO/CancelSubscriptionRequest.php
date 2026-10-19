<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\DTO;

final class CancelSubscriptionRequest
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly bool $atPeriodEnd = true
    ) {}
}
