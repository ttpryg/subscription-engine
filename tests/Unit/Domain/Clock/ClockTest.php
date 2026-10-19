<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Tests\Unit\Domain\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\SubscriptionEngine\Domain\Clock\FixedClock;
use Ttpryg\SubscriptionEngine\Domain\Clock\SystemClock;

class ClockTest extends TestCase
{
    public function test_system_clock_returns_the_current_time(): void
    {
        $before = new DateTimeImmutable;
        $now = (new SystemClock)->now();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThanOrEqual((new DateTimeImmutable)->getTimestamp(), $now->getTimestamp());
    }

    public function test_fixed_clock_returns_the_configured_time(): void
    {
        $moment = new DateTimeImmutable('2026-01-01 12:00:00');
        $clock = new FixedClock($moment);

        $this->assertSame($moment, $clock->now());
    }

    public function test_fixed_clock_can_be_set_to_a_new_time(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-01-01 12:00:00'));
        $newMoment = new DateTimeImmutable('2026-02-01 12:00:00');

        $clock->set($newMoment);

        $this->assertSame($newMoment, $clock->now());
    }

    public function test_fixed_clock_can_advance_by_a_modifier(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-01-01 00:00:00'));

        $clock->advance('+2 days');

        $this->assertSame('2026-01-03 00:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }
}
