<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;

final class BillingPeriod
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('Billing period end must be after start');
        }
    }

    public static function starting(DateTimeImmutable $start, BillingInterval $interval, int $count = 1): self
    {
        return new self($start, $interval->addTo($start, $count));
    }

    public static function trial(DateTimeImmutable $start, int $days): self
    {
        return new self($start, $start->modify(sprintf('+%d days', $days)));
    }

    public function next(BillingInterval $interval, int $count = 1): self
    {
        return self::starting($this->end, $interval, $count);
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->start && $moment < $this->end;
    }
}
