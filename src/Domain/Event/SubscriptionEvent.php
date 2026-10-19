<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Event;

use Ttpryg\SubscriptionEngine\Domain\Entity\Subscription;

/** Base class for all subscription lifecycle events. */
abstract class SubscriptionEvent
{
    public function __construct(public readonly Subscription $subscription) {}
}
