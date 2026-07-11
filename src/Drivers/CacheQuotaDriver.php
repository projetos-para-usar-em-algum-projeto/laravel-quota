<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Drivers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZaberDev\Quota\Contracts\QuotaDriverContract;
use ZaberDev\Quota\DTO\QuotaInfo;

/**
 * Cache-based quota driver storing usage counters using high-performance atomic cache increments.
 */
class CacheQuotaDriver implements QuotaDriverContract
{
    /**
     * @param CacheRepository $cache The cache repository instance.
     * @param string $prefix Prefix applied to all quota keys in the cache store.
     */
    public function __construct(
        protected CacheRepository $cache,
        protected string $prefix = 'quotas:',
    ) {}

    /**
     * {@inheritDoc}
     */
    public function get(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $storageKey = $this->resolveStorageKey($key, $periodStart, $periodEnd);

        $used = (int) $this->cache->get($storageKey, 0);

        return new QuotaInfo(
            key: $key,
            used: $used,
            limit: $limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $amount, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $storageKey = $this->resolveStorageKey($key, $periodStart, $periodEnd);

        if (! $this->cache->has($storageKey)) {
            $this->cache->add($storageKey, 0, CarbonImmutable::instance($periodEnd));
        }

        $used = (int) $this->cache->increment($storageKey, max(1, $amount));

        return new QuotaInfo(
            key: $key,
            used: $used,
            limit: $limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function decrement(string $key, int $amount, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $storageKey = $this->resolveStorageKey($key, $periodStart, $periodEnd);

        if (! $this->cache->has($storageKey)) {
            $this->cache->add($storageKey, 0, CarbonImmutable::instance($periodEnd));
        }

        $used = max(0, (int) $this->cache->decrement($storageKey, max(1, $amount)));

        return new QuotaInfo(
            key: $key,
            used: $used,
            limit: $limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, int $used, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo
    {
        $storageKey = $this->resolveStorageKey($key, $periodStart, $periodEnd);

        $used = max(0, $used);
        $this->cache->put($storageKey, $used, CarbonImmutable::instance($periodEnd));

        return new QuotaInfo(
            key: $key,
            used: $used,
            limit: $limit,
            periodStart: CarbonImmutable::instance($periodStart),
            periodEnd: CarbonImmutable::instance($periodEnd),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function reset(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd): void
    {
        $storageKey = $this->resolveStorageKey($key, $periodStart, $periodEnd);

        $this->cache->forget($storageKey);
    }

    /**
     * {@inheritDoc}
     */
    public function flush(string $key): void
    {
        // For atomic cache counters without start/end tags, we forget common windows or current active window
        $now = CarbonImmutable::now();
        $this->reset($key, $now->startOfMinute(), $now->endOfMinute());
        $this->reset($key, $now->startOfHour(), $now->endOfHour());
        $this->reset($key, $now->startOfDay(), $now->endOfDay());
        $this->reset($key, $now->startOfWeek(), $now->endOfWeek());
        $this->reset($key, $now->startOfMonth(), $now->endOfMonth());
        $this->reset($key, $now->startOfYear(), $now->endOfYear());
    }

    /**
     * Resolve the underlying cache key including prefix and period start timestamp.
     */
    protected function resolveStorageKey(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return sprintf(
            '%s%s:%s:%s',
            $this->prefix,
            $key,
            $periodStart->getTimestamp(),
            $periodEnd->getTimestamp()
        );
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

        return $this->cache->add($this->prefix . 'lock:' . $key, true, $seconds);
    }

    /**
     * Release an atomic in-flight reservation lock for the given key.
     */
    public function releaseLock(string $key): bool
    {
        return $this->cache->forget($this->prefix . 'lock:' . $key);
    }

    /**
     * Determine if an atomic in-flight reservation lock currently exists for the given key.
     */
    public function isLocked(string $key): bool
    {
        return $this->cache->has($this->prefix . 'lock:' . $key);
    }
}

