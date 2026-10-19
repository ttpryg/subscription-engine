<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Domain\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;
use Ttpryg\SubscriptionEngine\Domain\Service\ProrationCalculator;
use Ttpryg\SubscriptionEngine\Domain\ValueObject\BillingPeriod;

class ProrationCalculatorTest extends TestCase
{
    private ProrationCalculator $calculator;

    private DateTimeImmutable $start;

    private BillingPeriod $period;

    protected function setUp(): void
    {
        $this->calculator = new ProrationCalculator;
        $this->start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $this->period = BillingPeriod::starting($this->start, BillingInterval::MONTH);
    }

    public function test_upgrade_halfway_charges_the_difference_for_the_remaining_period(): void
    {
        $at = new DateTimeImmutable('2026-01-16 12:00:00', new DateTimeZone('UTC'));

        $amount = $this->calculator->calculate(
            $this->plan('monthly', 100000.0),
            $this->plan('pro', 200000.0),
            $this->period,
            $at
        );

        $this->assertSame(50000.0, $amount);
    }

    public function test_downgrade_never_produces_a_negative_charge(): void
    {
        $amount = $this->calculator->calculate(
            $this->plan('pro', 200000.0),
            $this->plan('monthly', 100000.0),
            $this->period,
            $this->start
        );

        $this->assertSame(0.0, $amount);
    }

    public function test_change_at_period_start_charges_the_full_price_difference(): void
    {
        $amount = $this->calculator->calculate(
            $this->plan('monthly', 100000.0),
            $this->plan('pro', 200000.0),
            $this->period,
            $this->start
        );

        $this->assertSame(100000.0, $amount);
    }

    public function test_change_at_period_end_charges_nothing(): void
    {
        $amount = $this->calculator->calculate(
            $this->plan('monthly', 100000.0),
            $this->plan('pro', 200000.0),
            $this->period,
            $this->period->end
        );

        $this->assertSame(0.0, $amount);
    }

    private function plan(string $id, float $price): Plan
    {
        return new Plan(
            id: $id,
            name: ucfirst($id),
            price: $price,
            interval: BillingInterval::MONTH
        );
    }
}
