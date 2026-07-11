<?php

declare(strict_types=1);

namespace ZaberDev\Quota\DTO;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Immutable Data Transfer Object representing the current quota state for a target action and period.
 *
 * @implements Arrayable<string, mixed>
 */
final class QuotaInfo implements Arrayable
{
    /**
     * @param string $key The resolved storage key.
     * @param int $used Total amount consumed during the current period.
     * @param int|null $limit The maximum allowed quota limit for the period (null means unlimited).
     * @param CarbonImmutable $periodStart Timestamp indicating when the current period started.
     * @param CarbonImmutable $periodEnd Timestamp indicating when the current period expires.
     */
    public function __construct(
        public readonly string $key,
        public readonly int $used,
        public readonly ?int $limit,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
    ) {}

    /**
     * Get the remaining quota available before exceeding the limit.
     * Returns PHP_INT_MAX if the quota limit is unlimited (null).
     */
    public function remaining(): int
    {
        if ($this->limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * Determine if the quota limit has been met or exceeded.
     */
    public function isExceeded(): bool
    {
        if ($this->limit === null) {
            return false;
        }

        return $this->used >= $this->limit;
    }

    /**
     * Determine if the quota has sufficient capacity to consume the specified amount.
     */
    public function hasCapacity(int $amount = 1): bool
    {
        if ($this->limit === null) {
            return true;
        }

        return ($this->used + $amount) <= $this->limit;
    }

    /**
     * Calculate the percentage of quota consumed.
     */
    public function usagePercentage(): float
    {
        if ($this->limit === null || $this->limit <= 0) {
            return 0.0;
        }

        return min(100.0, round(($this->used / $this->limit) * 100, 2));
    }

    /**
     * Determine whether the current quota period has elapsed/expired.
     */
    public function isExpired(): bool
    {
        return CarbonImmutable::now()->isAfter($this->periodEnd);
    }

    /**
     * Get a human-readable string representing the time remaining until the quota period resets.
     */
    public function resetsInForHumans(): string
    {
        if ($this->isExpired()) {
            return '0 seconds';
        }

        return CarbonImmutable::now()->diffForHumans($this->periodEnd, [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    /**
     * Convert the DTO instance to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'used' => $this->used,
            'limit' => $this->limit,
            'remaining' => $this->remaining(),
            'is_exceeded' => $this->isExceeded(),
            'usage_percentage' => $this->usagePercentage(),
            'period_start' => $this->periodStart->toIso8601String(),
            'period_end' => $this->periodEnd->toIso8601String(),
            'resets_in_for_humans' => $this->resetsInForHumans(),
        ];
    }
}
