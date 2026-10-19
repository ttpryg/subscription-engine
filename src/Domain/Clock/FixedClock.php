<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** Controllable clock, useful for tests and simulations. */
final class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
