<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Enum;

enum InvoiceStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case VOID = 'void';
}
