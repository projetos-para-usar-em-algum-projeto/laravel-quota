<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ZaberDev\Quota\Support\PendingQuota;

/**
 * Interface for the primary Quota manager and factory.
 */
interface QuotaManagerContract
{
    /**
     * Get a quota driver instance by name.
     *
     * @return QuotaDriverContract
     */
    public function driver($driver = null);

    /**
     * Begin constructing a quota check for the given action and optional target entity.
     */
    public function for(string $action, Model|string|int|null $target = null): PendingQuota;

    /**
     * Register a custom driver creator closure.
     *
     * @return $this
     */
    public function extend($driver, Closure $callback);
}
