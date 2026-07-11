<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use ZaberDev\Quota\Contracts\QuotaDriverContract;
use ZaberDev\Quota\Contracts\QuotaManagerContract;
use ZaberDev\Quota\Support\PendingQuota;

/**
 * Facade for accessing the Quota Manager.
 *
 * @method static QuotaDriverContract driver(?string $driver = null)
 * @method static PendingQuota for(string $action, Model|string|int|null $target = null)
 * @method static QuotaManagerContract extend(string $driver, Closure $callback)
 *
 * @see \ZaberDev\Quota\QuotaManager
 */
class Quota extends Facade
{
    /**
     * Get the registered name of the component in the container.
     */
    protected static function getFacadeAccessor(): string
    {
        return QuotaManagerContract::class;
    }
}
