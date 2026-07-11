<?php

declare(strict_types=1);

namespace ZaberDev\Quota;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Manager;
use ZaberDev\Quota\Contracts\QuotaDriverContract;
use ZaberDev\Quota\Contracts\QuotaManagerContract;
use ZaberDev\Quota\Drivers\CacheQuotaDriver;
use ZaberDev\Quota\Drivers\DatabaseQuotaDriver;
use ZaberDev\Quota\Exceptions\QuotaDriverNotFoundException;
use ZaberDev\Quota\Support\PendingQuota;

/**
 * Manager class responsible for resolving and caching quota drivers.
 */
class QuotaManager extends Manager implements QuotaManagerContract
{
    /**
     * Get the default driver name configured for the application.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('quotas.default', 'cache');
    }

    /**
     * Get a driver instance by name.
     */
    public function driver($driver = null)
    {
        $driverName = $driver ?: $this->getDefaultDriver();

        return parent::driver($driverName);
    }

    /**
     * Begin constructing a quota check for the given action and optional target.
     */
    public function for(string $action, Model|string|int|null $target = null): PendingQuota
    {
        return new PendingQuota($this, $action, $target);
    }

    /**
     * Register a custom driver closure creator.
     *
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        parent::extend($driver, $callback);

        return $this;
    }

    /**
     * Create an instance of the Cache driver.
     */
    protected function createCacheDriver(): CacheQuotaDriver
    {
        $storeName = $this->config->get('quotas.drivers.cache.store');
        $prefix = $this->config->get('quotas.drivers.cache.prefix', 'quotas:');

        $repository = $this->container['cache']->store($storeName);

        return new CacheQuotaDriver($repository, $prefix);
    }

    /**
     * Create an instance of the Database driver.
     */
    protected function createDatabaseDriver(): DatabaseQuotaDriver
    {
        $table = $this->config->get('quotas.drivers.database.table', 'quotas');

        return new DatabaseQuotaDriver($table);
    }

    /**
     * Handle requests for non-existent drivers cleanly with a typed exception.
     *
     * @throws QuotaDriverNotFoundException
     */
    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (\InvalidArgumentException $e) {
            throw QuotaDriverNotFoundException::forDriver((string) $driver);
        }
    }
}
