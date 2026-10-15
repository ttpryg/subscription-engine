<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Enums\BillingInterval;
use Ttpryg\SubscriptionEngine\Exceptions\PlanNotFoundException;
use Ttpryg\SubscriptionEngine\Repositories\MemoryPlanRepository;
use Ttpryg\SubscriptionEngine\Services\PlanService;

class PlanServiceTest extends TestCase
{
    private PlanService $service;

    private MemoryPlanRepository $planRepository;

    protected function setUp(): void
    {
        $this->planRepository = new MemoryPlanRepository;
        $this->service = new PlanService($this->planRepository);
    }

    public function test_create_and_get_plan(): void
    {
        $plan = $this->service->createPlan(
            id: 'plan_silver',
            name: 'Silver Plan',
            price: 75000.0,
            interval: BillingInterval::MONTH
        );

        $this->assertSame('plan_silver', $plan->id);
        $retrieved = $this->service->getPlan('plan_silver');
        $this->assertSame('Silver Plan', $retrieved->name);
    }

    public function test_get_non_existent_plan_throws_exception(): void
    {
        $this->expectException(PlanNotFoundException::class);
        $this->service->getPlan('non_existent');
    }

    public function test_list_active_plans(): void
    {
        $this->service->createPlan('p1', 'Plan 1', 50000.0, BillingInterval::MONTH);
        $this->service->createPlan('p2', 'Plan 2', 100000.0, BillingInterval::MONTH);

        $active = $this->service->listActivePlans();
        $this->assertCount(2, $active);

        $this->service->deactivatePlan('p1');
        $activeAfter = $this->service->listActivePlans();
        $this->assertCount(1, $activeAfter);
        $this->assertSame('p2', $activeAfter[0]->id);
    }

    public function test_change_price(): void
    {
        $this->service->createPlan('p1', 'Plan 1', 50000.0, BillingInterval::MONTH);
        $updated = $this->service->changePrice('p1', 65000.0);

        $this->assertSame(65000.0, $updated->price);
        $this->assertSame(65000.0, $this->service->getPlan('p1')->price);
    }

    public function test_activate_and_deactivate_plan(): void
    {
        $this->service->createPlan('p1', 'Plan 1', 50000.0, BillingInterval::MONTH);
        $this->service->deactivatePlan('p1');
        $this->assertFalse($this->service->getPlan('p1')->isActive);

        $this->service->activatePlan('p1');
        $this->assertTrue($this->service->getPlan('p1')->isActive);
    }
}
