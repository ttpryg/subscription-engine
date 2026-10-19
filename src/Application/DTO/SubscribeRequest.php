<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\DTO;

final class SubscribeRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $planId,
        public readonly ?string $storeId = null,
        public readonly bool $skipTrial = false
    ) {}
}
