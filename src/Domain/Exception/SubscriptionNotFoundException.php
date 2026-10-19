<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Exception;

use RuntimeException;

class SubscriptionNotFoundException extends RuntimeException implements ExceptionInterface
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Subscription "%s" not found', $id));
    }
}
