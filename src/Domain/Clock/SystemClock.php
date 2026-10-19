<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
