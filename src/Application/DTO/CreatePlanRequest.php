<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\DTO;

use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;

final class CreatePlanRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly float $price,
        public readonly BillingInterval $interval,
        public readonly int $intervalCount = 1,
        public readonly string $currency = 'IDR',
        public readonly int $trialDays = 0,
        public readonly ?string $description = null,
        public readonly ?string $storeId = null
    ) {}
}
