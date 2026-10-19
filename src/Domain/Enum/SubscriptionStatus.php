<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Enum;

enum SubscriptionStatus: string
{
    case TRIALING = 'trialing';
    case ACTIVE = 'active';
    case PAST_DUE = 'past_due';
    case PAUSED = 'paused';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';

    /** Whether the subscriber should currently have access to the service. */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::TRIALING, self::ACTIVE, self::PAST_DUE], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELED, self::EXPIRED], true);
    }
}
