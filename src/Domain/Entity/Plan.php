<?php

declare(strict_types=1);

namespace Ttpryg\SubscriptionEngine\Domain\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use Ttpryg\SubscriptionEngine\Domain\Enum\BillingInterval;

class Plan
{
    public readonly DateTimeImmutable $createdAt;

    public DateTimeImmutable $updatedAt;

    public function __construct(
        public readonly string $id,
        public string $name,
        public float $price,
        public readonly BillingInterval $interval,
        public readonly int $intervalCount = 1,
        public string $currency = 'IDR',
        public int $trialDays = 0,
        public ?string $description = null,
        public readonly ?string $storeId = null,
        public bool $isActive = true,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null
    ) {
        if ($price < 0) {
            throw new InvalidArgumentException('Plan price cannot be negative');
        }

        if ($intervalCount < 1) {
            throw new InvalidArgumentException('Plan interval count must be at least 1');
        }

        if ($trialDays < 0) {
            throw new InvalidArgumentException('Plan trial days cannot be negative');
        }

        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? $this->createdAt;
    }

    public function hasTrial(): bool
    {
        return $this->trialDays > 0;
    }

    public function changePrice(float $price): void
    {
        if ($price < 0) {
            throw new InvalidArgumentException('Plan price cannot be negative');
        }

        $this->price = $price;
        $this->touch();
    }

    public function deactivate(): void
    {
        $this->isActive = false;
        $this->touch();
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }
}
