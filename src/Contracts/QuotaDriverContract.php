<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Contracts;

use Carbon\CarbonInterface;
use ZaberDev\Quota\DTO\QuotaInfo;

/**
 * Interface representing a storage backend for managing quotas.
 */
interface QuotaDriverContract
{
    /**
     * Retrieve current quota usage details for the specified key and period window.
     */
    public function get(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo;

    /**
     * Increment the quota usage for the specified key by the given amount within the period window.
     */
    public function increment(string $key, int $amount, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo;

    /**
     * Decrement the quota usage for the specified key by the given amount within the period window.
     */
    public function decrement(string $key, int $amount, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo;

    /**
     * Explicitly set the consumed quota usage to a specific integer value within the period window.
     */
    public function set(string $key, int $used, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?int $limit = null): QuotaInfo;

    /**
     * Reset the quota usage for the specified key inside the period window.
     */
    public function reset(string $key, CarbonInterface $periodStart, CarbonInterface $periodEnd): void;

    /**
     * Flush all quota records associated with the key across all periods from storage.
     */
    public function flush(string $key): void;

    /**
     * Attempt to acquire an atomic in-flight reservation lock for the given key.
     */
    public function acquireLock(string $key, int $seconds = 15): bool;

    /**
     * Release an atomic in-flight reservation lock for the given key.
     */
    public function releaseLock(string $key): bool;

    /**
     * Determine if an atomic in-flight reservation lock currently exists for the given key.
     */
    public function isLocked(string $key): bool;
}

