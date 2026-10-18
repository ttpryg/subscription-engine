<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Exceptions;

use DomainException;
use Ttpryg\SubscriptionEngine\Enums\SubscriptionStatus;

class InvalidSubscriptionStateException extends DomainException implements ExceptionInterface
{
    public static function cannot(string $action, SubscriptionStatus $status): self
    {
        return new self(sprintf('Cannot %s a subscription with status "%s"', $action, $status->value));
    }
}
