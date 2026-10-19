<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Exception;

use DomainException;
use Ttpryg\SubscriptionEngine\Domain\Enum\SubscriptionStatus;

class InvalidSubscriptionStateException extends DomainException implements ExceptionInterface
{
    public static function cannot(string $action, SubscriptionStatus $status): self
    {
        return new self(sprintf('Cannot %s a subscription with status "%s"', $action, $status->value));
    }
}
