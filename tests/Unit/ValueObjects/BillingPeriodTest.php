<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;
use Ttpryg\SubscriptionEngine\ValueObjects\BillingPeriod;

class BillingPeriodTest extends TestCase
{
    public function test_rejects_end_before_or_equal_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $time = new DateTimeImmutable('2026-01-01 10:00:00');
        new BillingPeriod($time, $time);
    }

    public function test_starting_factory(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $period = BillingPeriod::starting($start, BillingInterval::MONTH, 1);

        $this->assertEquals($start, $period->start);
        $this->assertEquals(new DateTimeImmutable('2026-02-01 00:00:00'), $period->end);
    }

    public function test_trial_factory(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $period = BillingPeriod::trial($start, 14);

        $this->assertEquals($start, $period->start);
        $this->assertEquals(new DateTimeImmutable('2026-01-15 00:00:00'), $period->end);
    }

    public function test_next_period(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $period = BillingPeriod::starting($start, BillingInterval::MONTH, 1);
        $next = $period->next(BillingInterval::MONTH, 1);

        $this->assertEquals($period->end, $next->start);
        $this->assertEquals(new DateTimeImmutable('2026-03-01 00:00:00'), $next->end);
    }

    public function test_contains(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $end = new DateTimeImmutable('2026-02-01 00:00:00');
        $period = new BillingPeriod($start, $end);

        $this->assertTrue($period->contains(new DateTimeImmutable('2026-01-01 00:00:00')));
        $this->assertTrue($period->contains(new DateTimeImmutable('2026-01-15 12:00:00')));
        $this->assertFalse($period->contains(new DateTimeImmutable('2026-02-01 00:00:00')));
        $this->assertFalse($period->contains(new DateTimeImmutable('2025-12-31 23:59:59')));
    }
}
