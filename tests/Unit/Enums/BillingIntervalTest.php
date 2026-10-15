<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Enums;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;

class BillingIntervalTest extends TestCase
{
    public function test_adds_days(): void
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00');
        $interval = BillingInterval::DAY;

        $result = $interval->addTo($start, 5);
        $this->assertSame('2026-01-06 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_adds_weeks(): void
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00');
        $interval = BillingInterval::WEEK;

        $result = $interval->addTo($start, 2);
        $this->assertSame('2026-01-15 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_adds_months_regular(): void
    {
        $start = new DateTimeImmutable('2026-01-15 10:00:00');
        $interval = BillingInterval::MONTH;

        $result = $interval->addTo($start, 3);
        $this->assertSame('2026-04-15 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_adds_months_clamping_to_end_of_month(): void
    {
        // Jan 31 + 1 month in non-leap year (2026) -> Feb 28
        $start = new DateTimeImmutable('2026-01-31 12:00:00');
        $result = BillingInterval::MONTH->addTo($start, 1);
        $this->assertSame('2026-02-28 12:00:00', $result->format('Y-m-d H:i:s'));

        // Jan 31 + 1 month in leap year (2024) -> Feb 29
        $leapStart = new DateTimeImmutable('2024-01-31 12:00:00');
        $leapResult = BillingInterval::MONTH->addTo($leapStart, 1);
        $this->assertSame('2024-02-29 12:00:00', $leapResult->format('Y-m-d H:i:s'));
    }

    public function test_adds_years(): void
    {
        $start = new DateTimeImmutable('2026-05-10 10:00:00');
        $result = BillingInterval::YEAR->addTo($start, 2);
        $this->assertSame('2028-05-10 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_adds_year_on_leap_day(): void
    {
        // Feb 29 2024 + 1 year -> Feb 28 2025
        $start = new DateTimeImmutable('2024-02-29 10:00:00');
        $result = BillingInterval::YEAR->addTo($start, 1);
        $this->assertSame('2025-02-28 10:00:00', $result->format('Y-m-d H:i:s'));
    }
}
