<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\DTO;

final class ChangePlanRequest
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $newPlanId,
        public readonly bool $immediate = false
    ) {}
}
