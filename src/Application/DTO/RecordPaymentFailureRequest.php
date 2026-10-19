<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Application\DTO;

final class RecordPaymentFailureRequest
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly ?string $reason = null
    ) {}
}
