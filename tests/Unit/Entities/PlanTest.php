<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Entities;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Entities\Plan;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;

class PlanTest extends TestCase
{
    public function test_can_instantiate_plan(): void
    {
        $plan = new Plan(
            id: 'plan_basic',
            name: 'Basic Plan',
            price: 50000.0,
            interval: BillingInterval::MONTH,
            intervalCount: 1,
            currency: 'IDR',
            trialDays: 7,
            description: 'Basic membership'
        );

        $this->assertSame('plan_basic', $plan->id);
        $this->assertSame('Basic Plan', $plan->name);
        $this->assertSame(50000.0, $plan->price);
        $this->assertSame(7, $plan->trialDays);
        $this->assertTrue($plan->hasTrial());
        $this->assertTrue($plan->isActive);
    }

    public function test_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Plan(
            id: 'plan_invalid',
            name: 'Invalid',
            price: -100.0,
            interval: BillingInterval::MONTH
        );
    }

    public function test_rejects_zero_or_negative_interval_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Plan(
            id: 'plan_invalid',
            name: 'Invalid',
            price: 100.0,
            interval: BillingInterval::MONTH,
            intervalCount: 0
        );
    }

    public function test_rejects_negative_trial_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Plan(
            id: 'plan_invalid',
            name: 'Invalid',
            price: 100.0,
            interval: BillingInterval::MONTH,
            trialDays: -5
        );
    }

    public function test_change_price_and_activation(): void
    {
        $plan = new Plan(
            id: 'plan_1',
            name: 'Pro',
            price: 100000.0,
            interval: BillingInterval::MONTH
        );

        $plan->changePrice(120000.0);
        $this->assertSame(120000.0, $plan->price);

        $plan->deactivate();
        $this->assertFalse($plan->isActive);

        $plan->activate();
        $this->assertTrue($plan->isActive);
    }
}
