<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Service;

use DateTimeImmutable;
use Ttpryg\SubscriptionEngine\Domain\Entity\Plan;
use Ttpryg\SubscriptionEngine\Domain\ValueObject\BillingPeriod;

/**
 * Calculates the amount to charge when switching plans mid-cycle.
 *
 * The unused portion of the current plan is credited against the remaining
 * portion of the new plan. Downgrades never produce a negative charge.
 */
final class ProrationCalculator
{
    public function calculate(
        Plan $fromPlan,
        Plan $toPlan,
        BillingPeriod $period,
        DateTimeImmutable $at
    ): float {
        $totalSeconds = max(1, $period->end->getTimestamp() - $period->start->getTimestamp());
        $remainingSeconds = max(0, min($period->end->getTimestamp() - $at->getTimestamp(), $totalSeconds));
        $remainingRatio = $remainingSeconds / $totalSeconds;

        $unusedCredit = $fromPlan->price * $remainingRatio;
        $newPlanCost = $toPlan->price * $remainingRatio;

        return round(max(0.0, $newPlanCost - $unusedCredit), 2);
    }
}
