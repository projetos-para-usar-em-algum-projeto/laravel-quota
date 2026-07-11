<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Drivers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use ZaberDev\Quota\Contracts\QuotaDriverContract;
use ZaberDev\Quota\DTO\QuotaInfo;
use ZaberDev\Quota\Models\Quota;

/**
 * Database-backed quota driver storing usage details inside an Eloquent table.
 */
class DatabaseQuotaDriver implements QuotaDriverContract
{
    /**
     * @param string $table The table name used for quota records.
     */
    public function __construct(
        protected string $table = 'quotas',
    ) {}

    /**
     * {@inheritDoc}
     */
    public function get(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $record = Quota::query()
            ->where('key', $key)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        $used = $record ? (int) $record->used : 0;
        $resolvedLimit = $record?->limit ?? $limit;

        return new QuotaInfo(
            key: $key,
            used: $used,
            limit: $resolvedLimit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $amount, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $record = Quota::query()
            ->where('key', $key)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        $incrementAmount = max(1, $amount);

        if ($record) {
            $record->used += $incrementAmount;
            if ($limit !== null) {
                $record->limit = $limit;
            }
            $record->save();
        } else {
            [$action, $targetType, $targetId] = $this->parseKeyMetadata($key);

            $record = Quota::query()->create([
                'key' => $key,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'used' => $incrementAmount,
                'limit' => $limit,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }

        return new QuotaInfo(
            key: $key,
            used: (int) $record->used,
            limit: $record->limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function decrement(string $key, int $amount, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $record = Quota::query()
            ->where('key', $key)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        $decrementAmount = max(1, $amount);

        if ($record) {
            $record->used = max(0, $record->used - $decrementAmount);
            if ($limit !== null) {
                $record->limit = $limit;
            }
            $record->save();
        } else {
            [$action, $targetType, $targetId] = $this->parseKeyMetadata($key);

            $record = Quota::query()->create([
                'key' => $key,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'used' => 0,
                'limit' => $limit,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }

        return new QuotaInfo(
            key: $key,
            used: (int) $record->used,
            limit: $record->limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, int $used, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $record = Quota::query()
            ->where('key', $key)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        $resolvedUsed = max(0, $used);

        if ($record) {
            $record->used = $resolvedUsed;
            if ($limit !== null) {
                $record->limit = $limit;
            }
            $record->save();
        } else {
            [$action, $targetType, $targetId] = $this->parseKeyMetadata($key);

            $record = Quota::query()->create([
                'key' => $key,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'used' => $resolvedUsed,
                'limit' => $limit,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }

        return new QuotaInfo(
            key: $key,
            used: (int) $record->used,
            limit: $record->limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd): void
    {
        Quota::query()
            ->where('key', $key)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function flush(string $key): void
    {
        Quota::query()->where('key', $key)->delete();
    }

    /**
     * Attempt to acquire an atomic in-flight reservation lock for the given key.
     */
    public function acquireLock(string $key, int $seconds = 15): bool
    {
        if ($seconds <= 0) {
            $this->releaseLock($key);

            return false;
        }

        $lockKey = 'lock:' . $key;
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($seconds);

        return Quota::query()->getConnection()->transaction(function () use ($lockKey, $key, $expiresAt, $now) {
            $existing = Quota::query()->where('key', $lockKey)->lockForUpdate()->first();

            if ($existing) {
                if ($now->isAfter(CarbonImmutable::instance($existing->period_end))) {
                    $existing->delete();
                } else {
                    return false;
                }
            }

            [$action, $targetType, $targetId] = $this->parseKeyMetadata($key);

            try {
                Quota::query()->insert([
                    'key' => $lockKey,
                    'action' => 'lock:' . $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'used' => 0,
                    'limit' => null,
                    'period_start' => $now,
                    'period_end' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    /**
     * Release an atomic in-flight reservation lock for the given key.
     */
    public function releaseLock(string $key): bool
    {
        return Quota::query()->where('key', 'lock:' . $key)->delete() > 0;
    }

    /**
     * Determine if an atomic in-flight reservation lock currently exists for the given key.
     */
    public function isLocked(string $key): bool
    {
        $lockKey = 'lock:' . $key;
        $existing = Quota::query()->where('key', $lockKey)->first();

        if (! $existing) {
            return false;
        }

        if (CarbonImmutable::now()->isAfter(CarbonImmutable::instance($existing->period_end))) {
            $existing->delete();

            return false;
        }

        return true;
    }

    /**
     * Parse action, target type, and target ID from a formatted key string.
     *
     * @return array{0: string, 1: string|null, 2: string|int|null}
     */
    protected function parseKeyMetadata(string $key): array
    {
        $parts = explode(':', $key);
        $action = $parts[0] ?? $key;
        $targetType = null;
        $targetId = null;

        if (count($parts) >= 3) {
            $targetType = $parts[1];
            $targetId = $parts[2];
        } elseif (count($parts) === 2) {
            $targetId = $parts[1];
        }

        return [$action, $targetType, $targetId];
    }
}

