<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Enum;

use DateTimeImmutable;

enum BillingInterval: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';

    /**
     * Adds N intervals to a date. Month/year additions clamp to the last day
     * of the target month (e.g. Jan 31 + 1 month = Feb 28/29) instead of overflowing.
     */
    public function addTo(DateTimeImmutable $date, int $count = 1): DateTimeImmutable
    {
        return match ($this) {
            self::DAY => $date->modify(sprintf('+%d days', $count)),
            self::WEEK => $date->modify(sprintf('+%d days', $count * 7)),
            self::MONTH => self::addMonths($date, $count),
            self::YEAR => self::addMonths($date, $count * 12),
        };
    }

    private static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $totalMonths = (int) $date->format('Y') * 12 + ((int) $date->format('n') - 1) + $months;
        $year = intdiv($totalMonths, 12);
        $month = $totalMonths % 12 + 1;
        $day = (int) $date->format('j');
        $lastDay = (int) $date->setDate($year, $month, 1)->format('t');

        return $date->setDate($year, $month, min($day, $lastDay));
    }
}
