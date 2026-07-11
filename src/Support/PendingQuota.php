<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use ZaberDev\Quota\Contracts\QuotaDriverContract;
use ZaberDev\Quota\Contracts\QuotaManagerContract;
use ZaberDev\Quota\DTO\QuotaInfo;
use ZaberDev\Quota\Events\QuotaConsumed;
use ZaberDev\Quota\Events\QuotaExceeded;
use ZaberDev\Quota\Events\QuotaReset;
use ZaberDev\Quota\Exceptions\QuotaExceededException;

/**
 * Fluent builder class for querying, checking, and consuming quotas.
 */
class PendingQuota
{
    /**
     * @var string|null
     */
    protected ?string $driver = null;

    /**
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * @var CarbonImmutable
     */
    protected CarbonImmutable $periodStart;

    /**
     * @var CarbonImmutable
     */
    protected CarbonImmutable $periodEnd;

    /**
     * Create a new pending quota check instance.
     */
    public function __construct(
        protected QuotaManagerContract $manager,
        protected string $action,
        protected Model|string|int|null $target = null,
    ) {
        $now = CarbonImmutable::now();
        $this->periodStart = $now->startOfMonth();
        $this->periodEnd = $now->endOfMonth();
    }

    /**
     * Set the maximum quota limit for the active time window.
     */
    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    /**
     * Mark the quota as unlimited (no numerical cap).
     */
    public function unlimited(): self
    {
        $this->limit = null;

        return $this;
    }

    /**
     * Specify the quota storage driver to use.
     */
    public function using(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Set a custom start and end time window for the quota period.
     */
    public function period(CarbonInterface $start, CarbonInterface $end): self
    {
        $this->periodStart = CarbonImmutable::instance($start);
        $this->periodEnd = CarbonImmutable::instance($end);

        return $this;
    }

    /**
     * Scope the quota period to the current minute.
     */
    public function perMinute(): self
    {
        $now = CarbonImmutable::now();

        return $this->period($now->startOfMinute(), $now->endOfMinute());
    }

    /**
     * Scope the quota period to the current hour.
     */
    public function perHour(): self
    {
        $now = CarbonImmutable::now();

        return $this->period($now->startOfHour(), $now->endOfHour());
    }

    /**
     * Scope the quota period to the current day.
     */
    public function perDay(): self
    {
        $now = CarbonImmutable::now();

        return $this->period($now->startOfDay(), $now->endOfDay());
    }

    /**
     * Scope the quota period to the current calendar week.
     */
    public function perWeek(): self
    {
        $now = CarbonImmutable::now();

        return $this->period($now->startOfWeek(), $now->endOfWeek());
    }

    /**
     * Scope the quota period to the current calendar month (default).
     */
    public function perMonth(): self
    {
        $now = CarbonImmutable::now();

        return $this->period($now->startOfMonth(), $now->endOfMonth());
    }

    /**
     * Scope the quota period to the current calendar year.
     */
    public function perYear(): self
    {
        $now = CarbonImmutable::now();

        return $this->period($now->startOfYear(), $now->endOfYear());
    }

    /**
     * Get the current quota information DTO.
     */
    public function info(): QuotaInfo
    {
        return $this->getDriver()->get(
            key: $this->resolveKey(),
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            limit: $this->limit,
        );
    }

    /**
     * Get the total quota amount used so far in the current period.
     */
    public function used(): int
    {
        return $this->info()->used;
    }

    /**
     * Get the remaining quota available in the current period.
     */
    public function remaining(): int
    {
        return $this->info()->remaining();
    }

    /**
     * Determine if the quota limit has been met or exceeded.
     */
    public function isExceeded(): bool
    {
        return $this->info()->isExceeded();
    }

    /**
     * Determine if the quota has sufficient capacity for the specified amount.
     */
    public function hasCapacity(int $amount = 1): bool
    {
        return $this->info()->hasCapacity($amount);
    }

    /**
     * Enforce the quota limit, throwing a QuotaExceededException if currently exceeded or locked.
     *
     * @throws QuotaExceededException
     */
    public function enforce(?string $message = null): self
    {
        $info = $this->info();
        $isLocked = $this->isLocked();

        if ($info->isExceeded() || $isLocked) {
            if ($this->shouldDispatchEvents()) {
                event(new QuotaExceeded($this->resolveKey(), $info, $this->action, $this->target));
            }

            $msg = $message ?? ($isLocked ? "Action [{$this->action}] is currently locked or being processed. Please wait." : null);

            throw new QuotaExceededException($info, $this->action, $this->target, $msg);
        }

        return $this;
    }

    /**
     * Attempt to consume the specified quota amount, throwing an exception if exceeded.
     *
     * @throws QuotaExceededException
     */
    public function consume(int $amount = 1): QuotaInfo
    {
        $info = $this->info();

        if (! $info->hasCapacity($amount)) {
            if ($this->shouldDispatchEvents()) {
                event(new QuotaExceeded($this->resolveKey(), $info, $this->action, $this->target));
            }

            throw new QuotaExceededException($info, $this->action, $this->target);
        }

        $updatedInfo = $this->increment($amount);

        if ($this->shouldDispatchEvents()) {
            event(new QuotaConsumed($this->resolveKey(), $amount, $updatedInfo, $this->action, $this->target));
        }

        return $updatedInfo;
    }

    /**
     * Attempt to consume the specified amount without throwing an exception.
     * Returns true on success, false if the quota is insufficient.
     */
    public function attempt(int $amount = 1): bool
    {
        if (! $this->hasCapacity($amount)) {
            if ($this->shouldDispatchEvents()) {
                event(new QuotaExceeded($this->resolveKey(), $this->info(), $this->action, $this->target));
            }

            return false;
        }

        $this->consume($amount);

        return true;
    }

    /**
     * Increment the quota usage forcibly without checking capacity constraints.
     */
    public function increment(int $amount = 1): QuotaInfo
    {
        return $this->getDriver()->increment(
            key: $this->resolveKey(),
            amount: $amount,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            limit: $this->limit,
        );
    }

    /**
     * Decrement the quota usage by the specified amount.
     */
    public function decrement(int $amount = 1): QuotaInfo
    {
        return $this->getDriver()->decrement(
            key: $this->resolveKey(),
            amount: $amount,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            limit: $this->limit,
        );
    }

    /**
     * Explicitly set the quota usage to the given value.
     */
    public function set(int $used): QuotaInfo
    {
        return $this->getDriver()->set(
            key: $this->resolveKey(),
            used: $used,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            limit: $this->limit,
        );
    }

    /**
     * Reset the quota usage for the current time period window.
     */
    public function reset(): void
    {
        $this->getDriver()->reset($this->resolveKey(), $this->periodStart, $this->periodEnd);

        if ($this->shouldDispatchEvents()) {
            event(new QuotaReset($this->resolveKey(), $this->action, $this->target));
        }
    }

    /**
     * Flush all usage records for this action across all historical and future periods.
     */
    public function flush(): void
    {
        $this->getDriver()->flush($this->resolveKey());
    }

    /**
     * Attempt to acquire an atomic in-flight reservation lock for this quota check.
     */
    public function acquireLock(int $seconds = 15): bool
    {
        $driver = $this->getDriver();

        if (method_exists($driver, 'acquireLock')) {
            return $driver->acquireLock($this->resolveKey(), $seconds);
        }

        return false;
    }

    /**
     * Release an atomic in-flight reservation lock for this quota check.
     */
    public function releaseLock(): bool
    {
        $driver = $this->getDriver();

        if (method_exists($driver, 'releaseLock')) {
            return $driver->releaseLock($this->resolveKey());
        }

        return false;
    }

    /**
     * Determine if an atomic in-flight reservation lock currently exists for this quota check.
     */
    public function isLocked(): bool
    {
        $driver = $this->getDriver();

        if (method_exists($driver, 'isLocked')) {
            return $driver->isLocked($this->resolveKey());
        }

        return false;
    }

    /**
     * Execute a callback inside an atomic in-flight reservation, verifying capacity before and acquiring lock.
     * Consumes the specified amount of quota only when the callback completes successfully.
     *
     * @template T
     * @param Closure(): T $callback
     * @param int $amount
     * @param int $lockSeconds
     * @return T
     *
     * @throws QuotaExceededException
     */
    public function block(Closure $callback, int $amount = 1, int $lockSeconds = 15): mixed
    {
        $this->enforce();

        if (! $this->acquireLock($lockSeconds)) {
            $info = $this->info();
            $msg = "Action [{$this->action}] is currently locked or being processed. Please wait.";

            if ($this->shouldDispatchEvents()) {
                event(new QuotaExceeded($this->resolveKey(), $info, $this->action, $this->target));
            }

            throw new QuotaExceededException($info, $this->action, $this->target, $msg);
        }

        try {
            $result = $callback();

            $this->consume($amount);

            return $result;
        } finally {
            $this->releaseLock();
        }
    }


    /**
     * Resolve the storage key for the target entity or scalar.
     */
    public function resolveKey(): string
    {
        if ($this->target === null) {
            return $this->action;
        }

        if ($this->target instanceof Model) {
            return sprintf('%s:%s:%s', $this->action, $this->target->getMorphClass(), $this->target->getKey());
        }

        return sprintf('%s:%s', $this->action, $this->target);
    }

    /**
     * Resolve the active storage driver instance.
     */
    protected function getDriver(): QuotaDriverContract
    {
        return $this->manager->driver($this->driver);
    }

    /**
     * Determine if quota events should be dispatched based on config.
     */
    protected function shouldDispatchEvents(): bool
    {
        return (bool) config('quotas.events.dispatch', true);
    }
}

