<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Exception;

use RuntimeException;

class PlanNotFoundException extends RuntimeException implements ExceptionInterface
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Subscription plan "%s" not found', $id));
    }
}
